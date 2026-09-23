<div class="space-y-4" style="overflow-wrap:anywhere; max-height:65vh; overflow-y:auto">
    @foreach($reports as $report)
        <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
            <dl class="space-y-3">
                @foreach($report as $label => $value)
                    <div>
                        <dt class="font-semibold">{{ $label }}</dt>
                        <dd style="white-space:pre-wrap">@if(is_array($value)){{ $value ? implode("\n", $value) : 'Нет' }}@else{{ $value ?? '—' }}@endif</dd>
                    </div>
                @endforeach
            </dl>
        </div>
    @endforeach
</div>
