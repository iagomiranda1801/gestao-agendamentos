<?php

namespace App\Services\WhatsApp\Audio;

/** Resultado da tentativa de transcrever uma nota de voz do WhatsApp. */
final class AudioTranscription
{
    public const OK = 'ok';

    public const TOO_LONG = 'too_long';

    public const FAILED = 'failed';

    public const UNAVAILABLE = 'unavailable';

    public function __construct(
        public readonly string $status,
        public readonly string $text = '',
        public readonly ?string $model = null,
        public readonly ?int $inputTokens = null,
        public readonly ?int $outputTokens = null,
        public readonly ?int $seconds = null,
    ) {}

    public static function ok(string $text, ?string $model, ?int $inputTokens, ?int $outputTokens, ?int $seconds = null): self
    {
        return new self(self::OK, $text, $model, $inputTokens, $outputTokens, $seconds);
    }

    public static function failure(string $status, ?int $seconds = null): self
    {
        return new self($status, seconds: $seconds);
    }

    public function isOk(): bool
    {
        return $this->status === self::OK;
    }

    public function isFailure(): bool
    {
        return ! $this->isOk();
    }

    /** Resposta educada para o cliente quando não dá para usar o áudio. */
    public function notice(): string
    {
        return $this->status === self::TOO_LONG
            ? 'Seu áudio ficou longo demais pra eu ouvir por aqui. Pode mandar um áudio mais curto (até 3 minutos) ou escrever pra mim?'
            : 'Não consegui ouvir o áudio, pode me mandar por escrito?';
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'status' => $this->status, 'text' => $this->text, 'model' => $this->model,
            'input_tokens' => $this->inputTokens, 'output_tokens' => $this->outputTokens, 'seconds' => $this->seconds,
        ];
    }

    /** @param  array<string, mixed>  $data */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) ($data['status'] ?? self::FAILED),
            (string) ($data['text'] ?? ''),
            $data['model'] ?? null,
            isset($data['input_tokens']) ? (int) $data['input_tokens'] : null,
            isset($data['output_tokens']) ? (int) $data['output_tokens'] : null,
            isset($data['seconds']) ? (int) $data['seconds'] : null,
        );
    }
}
