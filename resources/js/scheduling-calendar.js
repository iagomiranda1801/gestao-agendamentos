import { Calendar } from '@fullcalendar/core';
import dayGridPlugin from '@fullcalendar/daygrid';
import timeGridPlugin from '@fullcalendar/timegrid';
import interactionPlugin from '@fullcalendar/interaction';
import listPlugin from '@fullcalendar/list';
import ptBrLocale from '@fullcalendar/core/locales/pt-br';

const calendars = new WeakMap();

window.initSchedulingCalendar = function initSchedulingCalendar({ element, wire, config }) {
    if (!element || calendars.has(element)) {
        return;
    }

    const calendar = new Calendar(element, {
        plugins: [dayGridPlugin, timeGridPlugin, interactionPlugin, listPlugin],
        locale: ptBrLocale,
        height: 'auto',
        expandRows: true,
        nowIndicator: true,
        selectable: config.canManage,
        editable: config.canManage,
        eventDurationEditable: false,
        initialView: config.initialView ?? 'timeGridWeek',
        slotMinTime: config.slotMinTime ?? '07:00:00',
        slotMaxTime: config.slotMaxTime ?? '22:00:00',
        firstDay: config.firstDay ?? 1,
        slotDuration: config.slotDuration ?? '00:15:00',
        headerToolbar: {
            left: 'prev,next today',
            center: 'title',
            right: 'dayGridMonth,timeGridWeek,timeGridDay,listWeek',
        },
        buttonText: {
            today: 'Hoje',
            month: 'Mês',
            week: 'Semana',
            day: 'Dia',
            list: 'Lista',
        },
        events: async (info, successCallback, failureCallback) => {
            try {
                const events = await wire.fetchEvents(info.startStr, info.endStr);
                successCallback(events);
            } catch (error) {
                failureCallback(error);
            }
        },
        select: (info) => {
            if (!config.canManage) {
                calendar.unselect();

                return;
            }

            if (info.jsEvent?.shiftKey) {
                wire.openCreateBlockFromSelection(info.startStr, info.endStr);
            } else {
                wire.openCreateFromSelection(info.startStr, info.endStr);
            }

            calendar.unselect();
        },
        eventClick: (info) => {
            if (info.event.extendedProps?.type === 'schedule_block') {
                return;
            }

            const url = info.event.extendedProps?.viewUrl;

            if (url) {
                window.location.href = url;
            }
        },
        eventDrop: async (info) => {
            if (!config.canManage || !info.event.extendedProps?.editable) {
                info.revert();

                return;
            }

            const result = await wire.rescheduleFromDrag(
                Number(info.event.id),
                info.event.start.toISOString(),
            );

            if (!result?.success) {
                info.revert();
                window.dispatchEvent(new CustomEvent('notify', {
                    detail: {
                        status: 'danger',
                        message: result?.message ?? 'Não foi possível remarcar o agendamento.',
                    },
                }));
            }
        },
    });

    calendar.render();
    calendars.set(element, calendar);

    window.addEventListener('scheduling-calendar:refresh', () => {
        calendar.refetchEvents();
    });

    if (typeof Livewire !== 'undefined') {
        Livewire.on('scheduling-calendar:refresh', () => {
            calendar.refetchEvents();
        });
    }

    document.addEventListener('livewire:navigating', () => {
        calendar.destroy();
        calendars.delete(element);
    });
};
