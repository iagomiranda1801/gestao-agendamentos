<?php

namespace App\Filament\App\Pages;

use App\Filament\App\Resources\ServiceOrders\ServiceOrderResource;
use App\Models\AssistanceBotConversation;
use App\Models\AssistanceBotRequest;
use App\Models\Company;
use App\Models\User;
use App\Services\ServiceOrders\Bot\AssistanceBotService;
use App\Services\ServiceOrders\Bot\AssistanceFlowService;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Locked;
use Livewire\WithPagination;
use UnitEnum;

class AssistanceConversationsPage extends Page
{
    use WithPagination;

    protected static ?string $slug = 'atendimentos-assistencia';

    protected static ?string $navigationLabel = 'Atendimentos da assistência';

    protected static ?string $title = 'Atendimentos da assistência';

    protected static string|UnitEnum|null $navigationGroup = 'WhatsApp';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleLeftRight;

    protected string $view = 'filament.app.pages.assistance-conversations';

    #[Locked]
    public ?int $selectedId = null;

    public static function canAccess(): bool
    {
        return Filament::getTenant() instanceof Company && auth()->user() instanceof User
            && app(AssistanceFlowService::class)->allows(Filament::getTenant(), auth()->user(), false);
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    public function conversations()
    {
        abort_unless(static::canAccess(), 403);

        return AssistanceBotConversation::where('company_id', Filament::getTenant()->id)->with('client')->latest('updated_at')->paginate(20);
    }

    public function selectConversation(int $id): void
    {
        abort_unless(static::canAccess(), 403);
        AssistanceBotConversation::where('company_id', Filament::getTenant()->id)->findOrFail($id);
        $this->selectedId = $id;
    }

    public function selectedConversation(): ?AssistanceBotConversation
    {
        abort_unless(static::canAccess(), 403);

        return $this->selectedId ? AssistanceBotConversation::where('company_id', Filament::getTenant()->id)->findOrFail($this->selectedId) : null;
    }

    public function setHuman(bool $human): void
    {
        abort_unless(static::canAccess(), 403);
        abort_unless($this->selectedId, 422);
        app(AssistanceBotService::class)->setHuman(Filament::getTenant(), auth()->user(), $this->selectedId, $human);
    }

    public function receiveDevice(int $requestId): void
    {
        abort_unless(static::canAccess(), 403);
        $order = app(AssistanceBotService::class)->receiveDevice(Filament::getTenant(), auth()->user(), $requestId);
        $this->redirect(ServiceOrderResource::getUrl('edit', ['record' => $order]));
    }

    public function completeInterest(int $requestId): void
    {
        abort_unless(static::canAccess(), 403);
        $request = AssistanceBotRequest::where('company_id', Filament::getTenant()->id)->where('kind', 'accessory')->findOrFail($requestId);
        $request->update(['status' => 'attended']);
    }
}
