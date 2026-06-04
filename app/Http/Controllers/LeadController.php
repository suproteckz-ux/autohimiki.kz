<?php

namespace App\Http\Controllers;

use App\Models\Lead;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * LeadController
 *
 * Безопасный приём заявок:
 * - Rate limit: 5 заявок с одного IP за 10 минут
 * - Валидация телефона: только казахстанские и российские номера
 * - Honeypot-защита от ботов
 * - XSS-фильтрация входных данных
 */
class LeadController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        // ── Rate Limiting ─────────────────────────────────────────
        $key = 'lead_store:' . $request->ip();

        if (RateLimiter::tooManyAttempts($key, 5)) {
            $seconds = RateLimiter::availableIn($key);
            return response()->json([
                'success' => false,
                'message' => "Слишком много заявок. Попробуйте через {$seconds} секунд.",
            ], 429);
        }
        RateLimiter::hit($key, 600); // 10 минут

        // ── Honeypot-защита ───────────────────────────────────────
        // Скрытое поле website — боты заполняют его, люди нет
        if ($request->filled('website')) {
            // Тихо возвращаем успех — бот не знает что заблокирован
            return response()->json(['success' => true]);
        }

        // ── Валидация ─────────────────────────────────────────────
        try {
            $validated = $request->validate([
                'name'         => ['required', 'string', 'min:2', 'max:100'],
                'phone'        => ['required', 'string', 'regex:/^(\+7|8|7)[0-9]{10}$/'],
                'product_name' => ['nullable', 'string', 'max:255'],
                'product_id'   => ['nullable', 'integer', 'exists:products,id'],
                'comment'      => ['nullable', 'string', 'max:1000'],
                'source'       => ['nullable', 'string', 'in:form,whatsapp,phone,callback'],
            ], [
                'name.required'   => 'Введите ваше имя',
                'name.min'        => 'Имя слишком короткое',
                'phone.required'  => 'Введите номер телефона',
                'phone.regex'     => 'Некорректный номер телефона. Пример: +77001234567',
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'errors'  => $e->errors(),
                'message' => collect($e->errors())->flatten()->first(),
            ], 422);
        }

        // ── XSS-фильтрация ────────────────────────────────────────
        // strip_tags убирает HTML, htmlspecialchars не нужен — данные хранятся как текст
        $name    = strip_tags($validated['name']);
        $comment = isset($validated['comment'])
            ? strip_tags($validated['comment'])
            : null;

        // ── Создание заявки ───────────────────────────────────────
        Lead::create([
            'name'         => $name,
            'phone'        => $this->normalizePhone($validated['phone']),
            'product_name' => $validated['product_name'] ?? null,
            'product_id'   => $validated['product_id'] ?? null,
            'comment'      => $comment,
            'source'       => $validated['source'] ?? 'form',
            'status'       => 'new',
        ]);

        return response()->json(['success' => true]);
    }

    /**
     * Нормализует телефон к формату +7XXXXXXXXXX
     */
    private function normalizePhone(string $phone): string
    {
        // Убираем всё кроме цифр
        $digits = preg_replace('/\D/', '', $phone);

        // 8XXXXXXXXXX → +7XXXXXXXXXX
        if (str_starts_with($digits, '8') && strlen($digits) === 11) {
            $digits = '7' . substr($digits, 1);
        }

        return '+' . $digits;
    }
}
