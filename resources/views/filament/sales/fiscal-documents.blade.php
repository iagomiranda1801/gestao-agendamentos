<div class="space-y-4">
    @foreach ($documents as $document)
        <div class="space-y-2">
            <p class="font-semibold">
                {{ $document->typeLabel() }} · {{ $document->statusLabel() }}
            </p>
            <p>
                R$ {{ number_format((float) $document->amount, 2, ',', '.') }}
                @if ($document->number)
                    · Nº {{ $document->number }}
                @endif
            </p>
            @if ($document->error)
                <p>{{ $document->error }}</p>
            @endif
            @if (in_array($document->status, ['authorized', 'cancelled'], true))
                @if ($document->pdf_url)
                    <a href="{{ $document->pdf_url }}" target="_blank" rel="noopener noreferrer" class="underline">Abrir PDF</a>
                @endif
                @if ($document->xml_url)
                    <a href="{{ $document->xml_url }}" target="_blank" rel="noopener noreferrer" class="underline">Abrir XML</a>
                @endif
            @endif
        </div>
    @endforeach
</div>
