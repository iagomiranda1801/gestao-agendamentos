<script data-navigate-once>
    document.addEventListener('livewire:initialized', () => {
        const notify = () => window.dispatchEvent(new CustomEvent('notificationsSent'));

        document.addEventListener('livewire:navigated', notify);
    });
</script>
