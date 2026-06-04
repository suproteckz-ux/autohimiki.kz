<div x-data="{
        open: false,
        sent: false,
        loading: false,
        name: '',
        phone: '',
        product_name: '',
        comment: '',
        source: 'form',
        website: '',      {{-- ИСПРАВЛЕНИЕ SEC-3: honeypot field --}}
        error: '',
        async submit() {
            this.loading = true;
            this.error = '';
            try {
                const res = await fetch('/lead', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                    },
                    body: JSON.stringify({
                        name:         this.name,
                        phone:        this.phone,
                        product_name: this.product_name,
                        comment:      this.comment,
                        source:       this.source,
                        website:      this.website,  {{-- honeypot --}}
                    }),
                });

                const data = await res.json().catch(() => ({}));

                if (res.ok && data.success) {
                    this.sent = true;
                } else if (res.status === 429) {
                    this.error = data.message || 'Слишком много запросов. Попробуйте позже.';
                } else if (res.status === 422 && data.message) {
                    this.error = data.message;
                } else {
                    this.error = 'Произошла ошибка. Позвоните нам: ' + (document.querySelector('[data-phone]')?.dataset.phone || '');
                }
            } catch {
                this.error = 'Нет соединения. Пожалуйста, позвоните нам.';
            }
            this.loading = false;
        }
     }"
     @open-lead-modal.window="
        open = true;
        sent = false;
        error = '';
        const d = $event.detail || {};
        product_name = d.product || '';
        source = d.source || 'form';
     "
     @keydown.escape.window="open = false">

    {{-- Backdrop --}}
    <div x-show="open"
         x-transition:enter="transition ease-out duration-200"
         x-transition:enter-start="opacity-0"
         x-transition:enter-end="opacity-100"
         x-transition:leave="transition ease-in duration-150"
         x-transition:leave-end="opacity-0"
         @click="open = false"
         class="fixed inset-0 bg-black/50 z-50 backdrop-blur-sm"
         style="display:none">
    </div>

    {{-- Modal --}}
    <div x-show="open"
         x-transition:enter="transition ease-out duration-200"
         x-transition:enter-start="opacity-0 scale-95"
         x-transition:enter-end="opacity-100 scale-100"
         x-transition:leave="transition ease-in duration-150"
         x-transition:leave-end="opacity-0 scale-95"
         class="fixed inset-0 z-50 flex items-center justify-center p-4"
         style="display:none"
         @click.self="open = false">

        <div class="bg-white rounded-2xl shadow-2xl w-full max-w-md p-6 relative">

            <button @click="open = false"
                    class="absolute top-4 right-4 text-gray-400 hover:text-gray-600"
                    aria-label="Закрыть">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                          d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>

            {{-- Успех --}}
            <div x-show="sent" class="text-center py-8">
                <div class="w-16 h-16 bg-green-100 rounded-full flex items-center justify-center mx-auto mb-4">
                    <svg class="w-8 h-8 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                    </svg>
                </div>
                <h3 class="text-xl font-bold text-gray-900 mb-2">Заявка отправлена!</h3>
                <p class="text-gray-500 text-sm">Мы перезвоним вам в ближайшее время.</p>
                <button @click="open = false"
                        class="mt-6 btn-primary justify-center w-full">
                    Закрыть
                </button>
            </div>

            {{-- Форма --}}
            <div x-show="!sent">
                <h3 class="text-xl font-bold text-gray-900 mb-1">Оставить заявку</h3>
                <p class="text-sm text-gray-500 mb-5">Перезвоним в течение 15 минут</p>

                <div class="space-y-4">

                    {{-- ИСПРАВЛЕНИЕ SEC-3: Honeypot поле
                         Скрыто через CSS — люди не видят и не заполняют.
                         Боты заполняют все поля подряд → website будет заполнено.
                         LeadController проверяет: if ($request->filled('website')) → тихий успех.
                         НЕ используем display:none — некоторые боты это обходят.
                         Используем position:absolute + clip + opacity:0. --}}
                    <div aria-hidden="true"
                         style="position:absolute;left:-9999px;top:-9999px;
                                opacity:0;height:0;overflow:hidden;pointer-events:none">
                        <label for="modal_website">Оставьте поле пустым</label>
                        <input type="text"
                               id="modal_website"
                               name="website"
                               x-model="website"
                               tabindex="-1"
                               autocomplete="off"
                               value="">
                    </div>

                    {{-- Имя --}}
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">
                            Ваше имя <span class="text-red-500">*</span>
                        </label>
                        <input type="text"
                               x-model="name"
                               placeholder="Иван"
                               autocomplete="given-name"
                               class="w-full px-4 py-3 border border-gray-200 rounded-xl
                                      focus:outline-none focus:ring-2 focus:ring-primary-400
                                      focus:border-transparent text-sm">
                    </div>

                    {{-- Телефон --}}
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">
                            Телефон <span class="text-red-500">*</span>
                        </label>
                        <input type="tel"
                               x-model="phone"
                               placeholder="+7 (700) 000-00-00"
                               autocomplete="tel"
                               class="w-full px-4 py-3 border border-gray-200 rounded-xl
                                      focus:outline-none focus:ring-2 focus:ring-primary-400
                                      focus:border-transparent text-sm">
                    </div>

                    {{-- Товар (если передан из карточки) --}}
                    <div x-show="product_name">
                        <label class="block text-sm font-medium text-gray-700 mb-1">Товар</label>
                        <input type="text"
                               x-model="product_name"
                               readonly
                               class="w-full px-4 py-3 border border-gray-100 rounded-xl
                                      bg-gray-50 text-sm text-gray-600">
                    </div>

                    {{-- Комментарий --}}
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Комментарий</label>
                        <textarea x-model="comment"
                                  rows="2"
                                  placeholder="Вопрос или уточнение..."
                                  class="w-full px-4 py-3 border border-gray-200 rounded-xl
                                         focus:outline-none focus:ring-2 focus:ring-primary-400
                                         focus:border-transparent text-sm resize-none"></textarea>
                    </div>

                    {{-- Ошибка --}}
                    <p x-show="error"
                       x-text="error"
                       class="text-sm text-red-500 bg-red-50 rounded-lg px-3 py-2">
                    </p>

                    {{-- Кнопка отправки --}}
                    <button @click="submit()"
                            :disabled="loading || !name.trim() || !phone.trim()"
                            class="btn-primary w-full justify-center
                                   disabled:opacity-50 disabled:cursor-not-allowed">
                        <span x-show="!loading">Отправить заявку</span>
                        <span x-show="loading" class="flex items-center gap-2">
                            <svg class="animate-spin w-4 h-4" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10"
                                        stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor"
                                      d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                            </svg>
                            Отправляем...
                        </span>
                    </button>

                    <p class="text-xs text-gray-400 text-center">
                        Нажимая кнопку, вы соглашаетесь на обработку персональных данных
                    </p>
                </div>
            </div>
        </div>
    </div>
</div>
