<div class="booking-wizard">
    <x-public-booking.step-indicator :steps="$steps" :current="$step" />

    <div class="booking-card">
        <div class="booking-card__header">
            @if ($step === \App\Livewire\PublicBooking\BookingWizard::STEP_CONFIRMATION)
                <h1 class="booking-title">Agendamento confirmado</h1>
                <p class="booking-subtitle">Guarde o código de confirmação para referência.</p>
            @else
                <h1 class="booking-title">{{ e($pageTitle) }}</h1>
                @if (filled($settings?->booking_page_description))
                    <p class="booking-subtitle">{{ e($settings->booking_page_description) }}</p>
                @endif
            @endif

            @if ($selectedService && $step !== \App\Livewire\PublicBooking\BookingWizard::STEP_SERVICE && $step !== \App\Livewire\PublicBooking\BookingWizard::STEP_CONFIRMATION)
                <div class="booking-selection-summary">
                    <span class="booking-selection-summary__item">{{ e($selectedService->name) }}</span>
                    @if ($selectedDate)
                        <span class="booking-selection-summary__separator">·</span>
                        <span class="booking-selection-summary__item">{{ e(\Carbon\CarbonImmutable::parse($selectedDate)->format('d/m/Y')) }}</span>
                    @endif
                    @if ($selectedTime)
                        <span class="booking-selection-summary__separator">·</span>
                        <span class="booking-selection-summary__item">{{ e($selectedTime) }}</span>
                    @endif
                </div>
            @endif
        </div>

        <div class="booking-card__body">
            @if ($errorMessage)
                <div class="booking-alert booking-alert--error" role="alert">
                    {{ e($errorMessage) }}
                </div>
            @endif

            @error('step')
                <div class="booking-alert booking-alert--error" role="alert">{{ $message }}</div>
            @enderror

            <div class="booking-honeypot" aria-hidden="true">
                <label for="website_url">Website</label>
                <input
                    id="website_url"
                    type="text"
                    wire:model="website_url"
                    tabindex="-1"
                    autocomplete="off"
                >
            </div>

            @if ($step === \App\Livewire\PublicBooking\BookingWizard::STEP_SERVICE)
                @if ($services->isEmpty())
                    <div class="booking-empty">Nenhum serviço disponível para agendamento online no momento.</div>
                @else
                    <x-public-booking.service-cards
                        :services="$services"
                        :selected-id="$serviceId"
                        :show-price="(bool) ($settings?->show_service_price ?? true)"
                        :show-duration="(bool) ($settings?->show_service_duration ?? true)"
                    />
                @endif
            @endif

            @if ($step === \App\Livewire\PublicBooking\BookingWizard::STEP_PROFESSIONAL)
                <div class="booking-option-list">
                    @if ($settings?->allow_no_professional_preference)
                        <button
                            type="button"
                            wire:key="professional-no-preference"
                            class="booking-option @if ($professionalSelection === \App\Livewire\PublicBooking\BookingWizard::NO_PREFERENCE) booking-option--selected @endif"
                            wire:click="selectProfessional('{{ \App\Livewire\PublicBooking\BookingWizard::NO_PREFERENCE }}')"
                            wire:loading.attr="disabled"
                            wire:target="selectProfessional"
                        >
                            <div class="booking-option__content">
                                <p class="booking-option__title">Sem preferência</p>
                                <p class="booking-option__subtitle">Escolheremos o profissional disponível</p>
                            </div>
                            @if ($professionalSelection === \App\Livewire\PublicBooking\BookingWizard::NO_PREFERENCE)
                                <span class="booking-option__check" aria-hidden="true">✓</span>
                            @endif
                        </button>
                    @endif

                    @foreach ($professionals as $professional)
                        <button
                            type="button"
                            wire:key="professional-{{ $professional->id }}"
                            class="booking-option @if ((int) $professionalSelection === $professional->id) booking-option--selected @endif"
                            wire:click="selectProfessional({{ $professional->id }})"
                            wire:loading.attr="disabled"
                            wire:target="selectProfessional"
                        >
                            <div class="booking-option__content">
                                <p class="booking-option__title">{{ e($professional->name) }}</p>
                                @if (filled($professional->specialty))
                                    <p class="booking-option__subtitle">{{ e($professional->specialty) }}</p>
                                @endif
                            </div>
                            @if ((int) $professionalSelection === $professional->id)
                                <span class="booking-option__check" aria-hidden="true">✓</span>
                            @endif
                        </button>
                    @endforeach
                </div>
            @endif

            @if ($step === \App\Livewire\PublicBooking\BookingWizard::STEP_SCHEDULE)
                <div class="booking-schedule">
                    @if (! $scheduleDatesLoaded)
                        <div class="booking-schedule__loading">
                            <span class="booking-loading booking-loading--primary" aria-hidden="true"></span>
                            <span>Carregando calendário...</span>
                        </div>
                    @else
                        <x-public-booking.date-calendar
                            :calendar-month="$calendarMonth"
                            :available-date-keys="$availableDateKeys"
                            :selected-date="$selectedDate"
                            :min-date="$scheduleMinDate"
                            :max-date="$scheduleMaxDate"
                        />

                        @if (count($availableDateKeys) === 0)
                            <div class="booking-empty">
                                Nenhum horário neste mês. Use as setas para ver outro mês.
                            </div>
                        @elseif ($selectedDate)
                            <div class="booking-schedule__slots">
                                <h3 class="booking-schedule__slots-title">
                                    Horários — {{ e(\Carbon\CarbonImmutable::parse($selectedDate)->format('d/m/Y')) }}
                                </h3>

                                <div wire:loading.flex wire:target="selectCalendarDate" class="booking-schedule__loading booking-schedule__loading--inline">
                                    <span class="booking-loading booking-loading--primary" aria-hidden="true"></span>
                                    <span>Buscando horários...</span>
                                </div>

                                <div wire:loading.remove wire:target="selectCalendarDate">
                                    @if ($availableSlots->isEmpty())
                                        <div class="booking-empty">Nenhum horário disponível para esta data.</div>
                                    @else
                                        <x-public-booking.time-slots-grid
                                            :slots="$availableSlots"
                                            :selected-time="$selectedTime"
                                        />
                                    @endif
                                </div>
                            </div>
                        @else
                            <p class="booking-step-hint">Toque em um dia disponível (destacado) para ver os horários.</p>
                        @endif
                    @endif
                </div>
            @endif

            @if ($step === \App\Livewire\PublicBooking\BookingWizard::STEP_CLIENT)
                <div class="booking-field">
                    <label class="booking-label" for="clientPhone">
                        Telefone / WhatsApp <span class="booking-label__required">*</span>
                    </label>
                    <div class="booking-field-row">
                        <input
                            id="clientPhone"
                            type="tel"
                            class="booking-input"
                            wire:model.live.debounce.400ms="clientPhone"
                            autocomplete="tel"
                            placeholder="(00) 00000-0000"
                            required
                        >
                        <span
                            class="booking-cpf-status"
                            wire:loading
                            wire:target="clientPhone"
                            aria-live="polite"
                        >Buscando...</span>
                    </div>
                    <p class="booking-step-hint">Digite seu telefone: se já for cliente, preenchemos nome e e-mail automaticamente.</p>
                    @error('clientPhone')
                        <p class="booking-field-error">{{ $message }}</p>
                    @enderror
                    @if ($clientLookupMessage)
                        <div class="booking-alert {{ $clientLookupFound ? 'booking-alert--success' : 'booking-alert--info' }}" role="status">
                            {{ e($clientLookupMessage) }}
                        </div>
                    @endif
                </div>

                <div class="booking-field">
                    <label class="booking-label" for="clientName">
                        Nome completo <span class="booking-label__required">*</span>
                    </label>
                    <input
                        id="clientName"
                        type="text"
                        class="booking-input"
                        wire:model="clientName"
                        autocomplete="name"
                    >
                    @error('clientName')
                        <p class="booking-field-error">{{ $message }}</p>
                    @enderror
                </div>

                <div class="booking-field">
                    <label class="booking-label" for="clientEmail">
                        E-mail
                        @if ($settings?->require_email_for_online_booking)
                            <span class="booking-label__required">*</span>
                        @endif
                    </label>
                    <input
                        id="clientEmail"
                        type="email"
                        class="booking-input"
                        wire:model="clientEmail"
                        autocomplete="email"
                    >
                    @error('clientEmail')
                        <p class="booking-field-error">{{ $message }}</p>
                    @enderror
                </div>

                <div class="booking-field">
                    <label class="booking-label" for="notes">Observações</label>
                    <textarea
                        id="notes"
                        class="booking-textarea"
                        wire:model="notes"
                        rows="3"
                        placeholder="Informações adicionais (opcional)"
                    ></textarea>
                    @error('notes')
                        <p class="booking-field-error">{{ $message }}</p>
                    @enderror
                </div>

                @if (filled($settings?->privacy_notice))
                    <div class="booking-field">
                        <label class="booking-checkbox">
                            <input type="checkbox" wire:model="privacyAccepted">
                            <span>{!! nl2br(e($settings->privacy_notice)) !!}</span>
                        </label>
                        @error('privacyAccepted')
                            <p class="booking-field-error">{{ $message }}</p>
                        @enderror
                    </div>
                @else
                    <div class="booking-field">
                        <label class="booking-checkbox">
                            <input type="checkbox" wire:model="privacyAccepted">
                            <span>Li e aceito a política de privacidade.</span>
                        </label>
                        @error('privacyAccepted')
                            <p class="booking-field-error">{{ $message }}</p>
                        @enderror
                    </div>
                @endif

                @if (filled($settings?->booking_terms))
                    <div class="booking-field">
                        <label class="booking-checkbox">
                            <input type="checkbox" wire:model="termsAccepted">
                            <span>{!! nl2br(e($settings->booking_terms)) !!}</span>
                        </label>
                        @error('termsAccepted')
                            <p class="booking-field-error">{{ $message }}</p>
                        @enderror
                    </div>
                @endif
            @endif

            @if ($step === \App\Livewire\PublicBooking\BookingWizard::STEP_REVIEW)
                <div class="booking-summary">
                    <div class="booking-summary__row">
                        <span class="booking-summary__label">Serviço</span>
                        <span class="booking-summary__value">{{ e($selectedService?->name ?? '—') }}</span>
                    </div>

                    @if ($showProfessionalStep)
                        <div class="booking-summary__row">
                            <span class="booking-summary__label">Profissional</span>
                            <span class="booking-summary__value">
                                @if ($professionalSelection === \App\Livewire\PublicBooking\BookingWizard::NO_PREFERENCE)
                                    Sem preferência
                                @else
                                    {{ e($selectedProfessional?->name ?? '—') }}
                                @endif
                            </span>
                        </div>
                    @endif

                    <div class="booking-summary__row">
                        <span class="booking-summary__label">Data</span>
                        <span class="booking-summary__value">
                            {{ $selectedDate ? e(\Carbon\CarbonImmutable::parse($selectedDate)->format('d/m/Y')) : '—' }}
                        </span>
                    </div>

                    <div class="booking-summary__row">
                        <span class="booking-summary__label">Horário</span>
                        <span class="booking-summary__value">{{ e($selectedTime ?? '—') }}</span>
                    </div>

                    @if ($settings?->show_service_price && $selectedService)
                        <div class="booking-summary__row">
                            <span class="booking-summary__label">Valor</span>
                            <span class="booking-summary__value">{{ $this->formatMoney((string) $selectedService->price) }}</span>
                        </div>
                    @endif

                    <div class="booking-summary__row">
                        <span class="booking-summary__label">Nome</span>
                        <span class="booking-summary__value">{{ e($clientName) }}</span>
                    </div>

                    <div class="booking-summary__row">
                        <span class="booking-summary__label">Telefone</span>
                        <span class="booking-summary__value">{{ e($clientPhone) }}</span>
                    </div>

                    @if (filled($clientEmail))
                        <div class="booking-summary__row">
                            <span class="booking-summary__label">E-mail</span>
                            <span class="booking-summary__value">{{ e($clientEmail) }}</span>
                        </div>
                    @endif

                    @if (filled($notes))
                        <div class="booking-summary__row">
                            <span class="booking-summary__label">Observações</span>
                            <span class="booking-summary__value">{{ e($notes) }}</span>
                        </div>
                    @endif
                </div>
            @endif

            @if ($step === \App\Livewire\PublicBooking\BookingWizard::STEP_CONFIRMATION)
            @if (filled($confirmationMessage))
                <div class="booking-alert booking-alert--success" role="status">
                    {{ e($confirmationMessage) }}
                </div>
            @endif

            @if ($whatsappQueued)
                <div class="booking-alert booking-alert--info" role="status">
                    Enviamos os detalhes no WhatsApp do número informado.
                </div>
            @endif

            <div class="booking-confirmation-code">
                    <p class="booking-confirmation-code__label">Código de confirmação</p>
                    <p class="booking-confirmation-code__value">{{ e($confirmationCode) }}</p>
                </div>

                @if (filled($manageUrl) && $manageUrl !== '#')
                    <a href="{{ $manageUrl }}" class="booking-btn booking-btn--primary booking-btn--block">
                        Gerenciar agendamento
                    </a>
                @endif
            @endif
        </div>

        @if ($step !== \App\Livewire\PublicBooking\BookingWizard::STEP_CONFIRMATION && $step !== \App\Livewire\PublicBooking\BookingWizard::STEP_SERVICE && $step !== \App\Livewire\PublicBooking\BookingWizard::STEP_PROFESSIONAL && $step !== \App\Livewire\PublicBooking\BookingWizard::STEP_SCHEDULE)
            <div class="booking-card__footer booking-card__footer--sticky">
                @if ($step !== \App\Livewire\PublicBooking\BookingWizard::STEP_SERVICE)
                    <button
                        type="button"
                        class="booking-btn booking-btn--secondary"
                        wire:click="goBack"
                        wire:loading.attr="disabled"
                        wire:target="goBack,goToReview,confirmBooking"
                    >
                        Voltar
                    </button>
                @endif

                @if ($step === \App\Livewire\PublicBooking\BookingWizard::STEP_CLIENT)
                    <button
                        type="button"
                        class="booking-btn booking-btn--primary"
                        wire:click="goToReview"
                        wire:loading.attr="disabled"
                        wire:target="goToReview,goBack,confirmBooking"
                    >
                        <span wire:loading.remove wire:target="goToReview">Revisar agendamento</span>
                        <span wire:loading wire:target="goToReview">
                            <span class="booking-loading"></span>
                            Carregando...
                        </span>
                    </button>
                @elseif ($step === \App\Livewire\PublicBooking\BookingWizard::STEP_REVIEW)
                    <button
                        type="button"
                        class="booking-btn booking-btn--primary"
                        wire:click="confirmBooking"
                        wire:loading.attr="disabled"
                        wire:target="confirmBooking,goBack,goToReview"
                        @disabled($isSubmitting)
                    >
                        <span wire:loading.remove wire:target="confirmBooking">Confirmar agendamento</span>
                        <span wire:loading wire:target="confirmBooking">
                            <span class="booking-loading"></span>
                            Processando...
                        </span>
                    </button>
                @endif
            </div>
        @elseif ($step !== \App\Livewire\PublicBooking\BookingWizard::STEP_CONFIRMATION && $step !== \App\Livewire\PublicBooking\BookingWizard::STEP_SERVICE)
            <div class="booking-card__footer booking-card__footer--back-only">
                <button
                    type="button"
                    class="booking-btn booking-btn--secondary booking-btn--link-style"
                    wire:click="goBack"
                    wire:loading.attr="disabled"
                    wire:target="goBack,selectService,selectProfessional,selectCalendarDate,selectTime"
                >
                    ← Voltar
                </button>
            </div>
        @endif
    </div>

    <div
        wire:loading.flex
        wire:target="selectService,selectProfessional,selectCalendarDate,selectTime,confirmBooking,loadScheduleAvailability"
        class="booking-loading-overlay"
        aria-live="polite"
        aria-busy="true"
    >
        <div class="booking-loading-panel">
            <span class="booking-loading booking-loading--primary" aria-hidden="true"></span>
            <span class="booking-loading-panel__text">Carregando...</span>
        </div>
    </div>
</div>

@push('head')
    <title>{{ e($pageTitle) }}</title>
    <meta name="description" content="{{ e($pageDescription) }}">
    <link rel="canonical" href="{{ $canonicalUrl }}">
    <meta property="og:type" content="website">
    <meta property="og:locale" content="pt_BR">
    <meta property="og:title" content="{{ e($pageTitle) }}">
    <meta property="og:description" content="{{ e($pageDescription) }}">
    <meta property="og:url" content="{{ $canonicalUrl }}">
    @if (filled($company->name))
        <meta property="og:site_name" content="{{ e($company->name) }}">
    @endif
@endpush
