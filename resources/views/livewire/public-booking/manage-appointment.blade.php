@push('head')
    <title>Gerenciar agendamento — {{ e($company->name) }}</title>
    <meta name="robots" content="noindex,nofollow">
@endpush

<div class="booking-card">
    <div class="booking-card__header">
        <h1 class="booking-title">Seu agendamento</h1>
        <p class="booking-subtitle">Consulte os detalhes ou gerencie sua reserva.</p>
    </div>

    <div class="booking-card__body">
        @if ($successMessage)
            <div class="booking-alert booking-alert--success" role="status">
                {{ e($successMessage) }}
            </div>
        @endif

        @if ($errorMessage)
            <div class="booking-alert booking-alert--error" role="alert">
                {{ e($errorMessage) }}
            </div>
        @endif

        <div class="booking-summary">
            <div class="booking-summary__row">
                <span class="booking-summary__label">Status</span>
                <span class="booking-summary__value">
                    <span class="booking-status-badge {{ $this->statusBadgeClass() }}">
                        {{ e($appointment->status->label()) }}
                    </span>
                </span>
            </div>

            @if (filled($appointment->public_confirmation_code))
                <div class="booking-summary__row">
                    <span class="booking-summary__label">Código</span>
                    <span class="booking-summary__value">{{ e($appointment->public_confirmation_code) }}</span>
                </div>
            @endif

            <div class="booking-summary__row">
                <span class="booking-summary__label">Serviço</span>
                <span class="booking-summary__value">{{ e($appointment->service_name_snapshot) }}</span>
            </div>

            <div class="booking-summary__row">
                <span class="booking-summary__label">Data</span>
                <span class="booking-summary__value">{{ e($localStart->format('d/m/Y')) }}</span>
            </div>

            <div class="booking-summary__row">
                <span class="booking-summary__label">Horário</span>
                <span class="booking-summary__value">
                    {{ e($localStart->format('H:i')) }} — {{ e($localEnd->format('H:i')) }}
                </span>
            </div>

            @if ($settings?->show_service_duration)
                <div class="booking-summary__row">
                    <span class="booking-summary__label">Duração</span>
                    <span class="booking-summary__value">{{ e((string) $appointment->duration_minutes_snapshot) }} min</span>
                </div>
            @endif

            @if ($settings?->show_service_price)
                <div class="booking-summary__row">
                    <span class="booking-summary__label">Valor</span>
                    <span class="booking-summary__value">{{ $this->formatMoney((string) $appointment->price_snapshot) }}</span>
                </div>
            @endif

            @if (filled($appointment->client_name_snapshot))
                <div class="booking-summary__row">
                    <span class="booking-summary__label">Nome</span>
                    <span class="booking-summary__value">{{ e($appointment->client_name_snapshot) }}</span>
                </div>
            @endif

            @if (filled($appointment->client_phone_snapshot))
                <div class="booking-summary__row">
                    <span class="booking-summary__label">Telefone</span>
                    <span class="booking-summary__value">{{ e($appointment->client_phone_snapshot) }}</span>
                </div>
            @endif

            @if (filled($appointment->client_email_snapshot))
                <div class="booking-summary__row">
                    <span class="booking-summary__label">E-mail</span>
                    <span class="booking-summary__value">{{ e($appointment->client_email_snapshot) }}</span>
                </div>
            @endif

            @if (filled($appointment->notes))
                <div class="booking-summary__row">
                    <span class="booking-summary__label">Observações</span>
                    <span class="booking-summary__value">{{ e($appointment->notes) }}</span>
                </div>
            @endif

            @if ($appointment->isCancelled() && filled($appointment->cancellation_reason))
                <div class="booking-summary__row">
                    <span class="booking-summary__label">Motivo do cancelamento</span>
                    <span class="booking-summary__value">{{ e($appointment->cancellation_reason) }}</span>
                </div>
            @endif
        </div>

        @if ($isViewOnly)
            <div class="booking-alert booking-alert--info" role="status">
                Este agendamento foi cancelado. Não é possível realizar alterações.
            </div>
        @elseif (! $canCancel && ! $canReschedule)
            <div class="booking-alert booking-alert--info" role="status">
                <strong>Alterações online indisponíveis.</strong>
                <ul class="booking-reason-list">
                    @if ($cancelUnavailableReason)
                        <li>{{ e($cancelUnavailableReason) }}</li>
                    @endif
                    @if ($rescheduleUnavailableReason && $rescheduleUnavailableReason !== $cancelUnavailableReason)
                        <li>{{ e($rescheduleUnavailableReason) }}</li>
                    @endif
                </ul>
            </div>
        @endif
    </div>

    @if (! $isViewOnly && ($canCancel || $canReschedule))
        <div class="booking-card__footer">
            @if ($canCancel)
                <button type="button" class="booking-btn booking-btn--danger" wire:click="openCancelModal">
                    Cancelar agendamento
                </button>
            @else
                <span></span>
            @endif

            @if ($canReschedule)
                <button type="button" class="booking-btn booking-btn--primary" wire:click="openRescheduleModal">
                    Reagendar
                </button>
            @endif
        </div>
    @endif
</div>

@if ($showCancelModal)
    <div class="booking-modal-backdrop" wire:click.self="closeCancelModal">
        <div class="booking-modal" role="dialog" aria-modal="true" aria-labelledby="cancel-modal-title">
            <div class="booking-modal__header">
                <h2 id="cancel-modal-title" class="booking-modal__title">Cancelar agendamento</h2>
            </div>
            <div class="booking-modal__body">
                <div class="booking-field">
                    <label class="booking-label" for="cancelReason">
                        Motivo do cancelamento <span class="booking-label__required">*</span>
                    </label>
                    <textarea
                        id="cancelReason"
                        class="booking-textarea"
                        wire:model="cancelReason"
                        rows="4"
                        placeholder="Descreva o motivo do cancelamento"
                    ></textarea>
                    @error('cancelReason')
                        <p class="booking-field-error">{{ $message }}</p>
                    @enderror
                </div>
            </div>
            <div class="booking-modal__footer">
                <button type="button" class="booking-btn booking-btn--secondary" wire:click="closeCancelModal">
                    Voltar
                </button>
                <button
                    type="button"
                    class="booking-btn booking-btn--danger"
                    wire:click="submitCancel"
                    wire:loading.attr="disabled"
                    @disabled($isSubmitting)
                >
                    Confirmar cancelamento
                </button>
            </div>
        </div>
    </div>
@endif

@if ($showRescheduleModal)
    <div class="booking-modal-backdrop" wire:click.self="closeRescheduleModal">
        <div class="booking-modal" role="dialog" aria-modal="true" aria-labelledby="reschedule-modal-title">
            <div class="booking-modal__header">
                <h2 id="reschedule-modal-title" class="booking-modal__title">Reagendar</h2>
            </div>
            <div class="booking-modal__body">
                @if ($rescheduleDates->isEmpty())
                    <div class="booking-empty">Nenhuma data disponível para reagendamento.</div>
                @else
                    <p class="booking-subtitle" style="margin-bottom: 1rem;">Escolha uma nova data e horário.</p>

                    <div class="booking-date-grid" style="margin-bottom: 1rem;">
                        @foreach ($rescheduleDates as $date)
                            <button
                                type="button"
                                class="booking-date-btn @if ($rescheduleDate === $date->format('Y-m-d')) booking-date-btn--selected @endif"
                                wire:click="$set('rescheduleDate', '{{ $date->format('Y-m-d') }}')"
                            >
                                <span class="booking-date-btn__weekday">{{ $this->weekdayLabel($date) }}</span>
                                <span class="booking-date-btn__day">{{ $date->format('d/m') }}</span>
                            </button>
                        @endforeach
                    </div>

                    @if ($rescheduleDate)
                        @if ($rescheduleSlots->isEmpty())
                            <div class="booking-empty">Nenhum horário disponível nesta data.</div>
                        @else
                            <x-public-booking.time-slots-grid
                                :slots="$rescheduleSlots"
                                :selected-time="$rescheduleTime"
                                action="selectRescheduleTime"
                            />
                        @endif
                    @endif
                @endif
            </div>
            <div class="booking-modal__footer">
                <button type="button" class="booking-btn booking-btn--secondary" wire:click="closeRescheduleModal">
                    Voltar
                </button>
                <button
                    type="button"
                    class="booking-btn booking-btn--primary"
                    wire:click="submitReschedule"
                    wire:loading.attr="disabled"
                    @disabled($isSubmitting || ! $rescheduleDate || ! $rescheduleTime)
                >
                    Confirmar reagendamento
                </button>
            </div>
        </div>
    </div>
@endif
