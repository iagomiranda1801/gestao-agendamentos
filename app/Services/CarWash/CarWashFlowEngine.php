<?php

namespace App\Services\CarWash;

use App\Support\VehiclePlate;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/** Executor do fluxo. A IA e as ações do sistema entram por callback; o bloco não executa PHP editável. */
class CarWashFlowEngine
{
    /**
     * @param  callable(string, array, string): array{reply: string, state?: array<string, mixed>, next?: string, stop?: bool}  $action
     * @param  callable(array, string, array): array{kind: string, outcome?: string, data?: array<string, mixed>, reply?: string}  $interpret
     * @return array{state: array<string, mixed>, reply: string, notify: ?string, excerpt: ?string}
     */
    public function advance(array $definition, array $state, ?string $input, array $context, callable $action, callable $interpret): array
    {
        $nodes = array_column($definition['nodes'], null, 'id');
        $state += ['cursor' => $definition['entry'], 'waiting' => false, 'data' => [], 'ended' => false];
        $output = [];
        $notify = null;
        if ($state['ended']) {
            return ['state' => $state, 'reply' => 'Digite menu para começar outro atendimento.', 'notify' => null, 'excerpt' => null];
        }

        if ($state['waiting'] && $input !== null) {
            $node = $nodes[$state['cursor']] ?? throw new RuntimeException('Invalid car wash flow cursor.');
            if ($node['type'] === 'question') {
                $answered = $this->answerQuestion($node, $state, $input, $context);
                if ($answered['stay']) {
                    return $this->done($state, $answered['reply']);
                }
                $state = $answered['state'];
            } elseif ($node['type'] === 'menu') {
                $index = ctype_digit(trim($input)) ? (int) trim($input) - 1 : null;
                $option = $index !== null ? ($node['options'][$index] ?? null) : collect($node['options'])->first(
                    fn ($option) => Str::lower(Str::ascii($option['label'])) === Str::lower(Str::ascii(trim($input)))
                );
                if (! $option) {
                    return $this->done($state, "Escolha uma das opções abaixo.\n".$this->prompt($node, $state, $context));
                }
                $state['cursor'] = $option['next'];
                $state['waiting'] = false;
            } elseif ($node['type'] === 'ai') {
                try {
                    $decision = $interpret($node, $input, $state);
                } catch (Throwable) {
                    $decision = ['kind' => 'unavailable'];
                }
                $outcome = collect($node['outcomes'] ?? [])->first(fn ($item) => $item['id'] === ($decision['outcome'] ?? ''));
                if (($decision['kind'] ?? '') === 'stay') {
                    $reply = trim((string) ($decision['reply'] ?? ''));

                    return $this->done($state, $reply !== '' ? $reply : $this->prompt($node, $state, $context));
                }
                if (($decision['kind'] ?? '') !== 'advance' || $outcome === null) {
                    $notify = ($decision['kind'] ?? '') === 'unavailable' ? 'ai_unavailable' : 'off_topic';

                    return $this->done($state, $this->text($definition['off_context_message'], $state, $context), $notify, $input);
                }
                $state['data'] = array_merge($state['data'], $decision['data'] ?? []);
                $state['cursor'] = $outcome['next'];
                $state['waiting'] = false;
            }
        }

        for ($i = 0; $i < 50; $i++) {
            $node = $nodes[$state['cursor']] ?? throw new RuntimeException('Invalid car wash flow cursor.');
            switch ($node['type']) {
                case 'question':
                case 'menu':
                case 'ai':
                    $output[] = $this->prompt($node, $state, $context);
                    $state['waiting'] = true;

                    return $this->done($state, implode("\n\n", $output), $notify);
                case 'end':
                    $output[] = $this->text($node['text'], $state, $context);
                    $state['ended'] = true;
                    $state['waiting'] = false;

                    return $this->done($state, implode("\n\n", $output), $notify);
                case 'message':
                    $output[] = $this->text($node['text'], $state, $context);
                    $state['cursor'] = $node['next'];
                    break;
                case 'action':
                    $produced = $action($node['action'], $state, $node['id']);
                    $output[] = $produced['reply'];
                    $state = $this->mergeState($state, $produced['state'] ?? []);
                    if (! empty($produced['stop'])) {
                        $state['ended'] = true;
                        $state['waiting'] = false;

                        return $this->done($state, implode("\n\n", $output), $notify);
                    }
                    $state['cursor'] = $produced['next'] ?? $node['next'];
                    $state['waiting'] = false;
                    break;
            }
        }

        throw new RuntimeException('Car wash flow exceeded automatic step limit.');
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array{stay: bool, reply?: string, state?: array<string, mixed>}
     */
    private function answerQuestion(array $node, array $state, string $input, array $context): array
    {
        if ($node['field'] === 'slot') {
            $slots = $state['data']['offered_slots'] ?? [];
            $index = ctype_digit(trim($input)) ? (int) trim($input) - 1 : null;
            $slot = is_int($index) ? ($slots[$index] ?? null) : null;
            if (! is_array($slot) || empty($slot['value'])) {
                return ['stay' => true, 'reply' => "Escolha o número de um horário da lista.\n".$this->prompt($node, $state, $context)];
            }
            $state['data']['selected_slot'] = $slot;
        } elseif ($node['field'] === 'plate') {
            $plate = $this->plateAnswer($input, $context);
            if ($plate === null) {
                return ['stay' => true, 'reply' => "Informe uma placa válida (ABC-1234 ou ABC1D23).\n".$this->prompt($node, $state, $context)];
            }
            $state['data']['plate'] = $plate;
        } else {
            $value = Str::squish($input);
            if (mb_strlen($value) < 2 || mb_strlen($value) > 100) {
                return ['stay' => true, 'reply' => "Informe uma resposta de 2 a 100 caracteres.\n".$this->text($node['text'], $state, $context)];
            }
            $state['data']['name'] = $value;
        }
        $state['cursor'] = $node['next'];
        $state['waiting'] = false;

        return ['stay' => false, 'state' => $state];
    }

    private function plateAnswer(string $input, array $context): ?string
    {
        $normalized = Str::lower(Str::ascii(trim($input)));
        $current = VehiclePlate::normalize($context['plate'] ?? null);
        if ($current && preg_match('/^(sim|s|isso|essa|e essa|pode ser)[!. ]*$/', $normalized)) {
            return $current;
        }
        $plate = VehiclePlate::normalize($input);

        return $plate !== null && strlen($plate) === 7 && VehiclePlate::isValid($plate) ? $plate : null;
    }

    /** @param  array<string, mixed>  $state */
    private function mergeState(array $state, array $patch): array
    {
        $data = $patch['data'] ?? null;
        unset($patch['data']);
        $state = array_merge($state, $patch);
        if (is_array($data)) {
            $state['data'] = array_replace($state['data'] ?? [], $data);
        }

        return $state;
    }

    private function prompt(array $node, array $state, array $context): string
    {
        if ($node['type'] === 'question' && ($node['field'] ?? '') === 'plate' && filled($context['plate'] ?? null) && empty($state['data']['plate'])) {
            $text = 'A placa cadastrada é '.VehiclePlate::format((string) $context['plate']).'. É essa mesmo? Se for, responda sim. Se não, me manda a placa certa.';
        } else {
            $text = $this->text((string) ($node['text'] ?? ''), $state, $context);
        }
        if ($node['type'] === 'menu') {
            foreach ($node['options'] as $i => $option) {
                $text .= "\n".($i + 1).'. '.$option['label'];
            }
        }

        return $text;
    }

    private function text(string $text, array $state, array $context): string
    {
        $slot = $state['data']['selected_slot']['label'] ?? '';

        return strtr(str_replace('\\n', "\n", $text), [
            '{nome}' => $context['name'] ?? 'Cliente',
            '{empresa}' => $context['company'] ?? 'Lava-jato',
            '{placa}' => VehiclePlate::format($state['data']['plate'] ?? null) ?? '',
            '{servico}' => (string) ($state['data']['service_name'] ?? ''),
            '{horario}' => (string) $slot,
        ]);
    }

    /** @return array{state: array<string, mixed>, reply: string, notify: ?string, excerpt: ?string} */
    private function done(array $state, string $reply, ?string $notify = null, ?string $excerpt = null): array
    {
        return ['state' => $state, 'reply' => $reply, 'notify' => $notify, 'excerpt' => $excerpt];
    }
}
