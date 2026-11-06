<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SaleFiscalDocument extends Model
{
    public const SERVICE = 'nfse';

    public const PRODUCT = 'nfe';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2'];
    }

    public function typeLabel(): string
    {
        return $this->type === self::SERVICE ? 'NFS-e' : 'NF-e';
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            'sending' => 'Enviando',
            'order_pending' => 'Pedido no Base ERP',
            'pending' => 'Aguardando autorização',
            'authorized' => 'Autorizada',
            'error', 'rejected' => 'Falha na emissão',
            'unknown' => 'Envio a confirmar',
            'cancelled' => 'Cancelada',
            default => $this->status,
        };
    }
}
