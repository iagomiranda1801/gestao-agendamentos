<div
    class="scheduling-calendar-card"
    wire:ignore
    x-data="{}"
    x-init="
        window.initSchedulingCalendar({
            element: $el.querySelector('#scheduling-calendar'),
            wire: @this,
            config: @js($this->getCalendarConfig()),
        })
    "
>
    @vite(['resources/js/scheduling-calendar.js', 'resources/css/scheduling-calendar.css'])
    <div id="scheduling-calendar" class="scheduling-calendar"></div>
</div>
