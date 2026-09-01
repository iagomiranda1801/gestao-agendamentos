<?php

namespace App\Services\Financial;

use App\DataTransferObjects\Financial\IncomeExpenseReport;
use App\DataTransferObjects\Financial\IncomeExpenseReportRow;
use App\Models\Company;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

class IncomeExpenseReportExporter
{
    public function __construct(
        protected IncomeExpenseReportAggregator $aggregator,
    ) {}

    public function excel(
        Company $company,
        CarbonImmutable $startLocal,
        CarbonImmutable $endLocal,
    ): BinaryFileResponse {
        $report = $this->aggregator->aggregate($company, $startLocal, $endLocal);
        $path = $this->writeExcel($company, $report);

        return response()->download(
            $path,
            $this->filename($company, $report, 'xlsx'),
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        )->deleteFileAfterSend(true);
    }

    public function pdf(
        Company $company,
        CarbonImmutable $startLocal,
        CarbonImmutable $endLocal,
    ): Response {
        $report = $this->aggregator->aggregate($company, $startLocal, $endLocal);

        return Pdf::loadView('reports.income-expense', [
            'company' => $company,
            'report' => $report,
        ])
            ->setPaper('a4', 'landscape')
            ->download($this->filename($company, $report, 'pdf'));
    }

    protected function writeExcel(Company $company, IncomeExpenseReport $report): string
    {
        $path = tempnam(sys_get_temp_dir(), 'receitas-gastos-').'.xlsx';
        $writer = new Writer;
        $writer->openToFile($path);

        $writer->addRow(Row::fromValues(['Receitas e gastos']));
        $writer->addRow(Row::fromValues(['Empresa', $company->name]));
        $writer->addRow(Row::fromValues(['Período', $report->periodStartLabel.' a '.$report->periodEndLabel]));
        $writer->addRow(Row::fromValues([]));
        $writer->addRow(Row::fromValues(['Total receitas', $this->brl($report->incomeTotal)]));
        $writer->addRow(Row::fromValues(['Total gastos', $this->brl($report->expenseTotal)]));
        $writer->addRow(Row::fromValues(['Saldo', $this->brl($report->balance)]));
        $writer->addRow(Row::fromValues([]));
        $writer->addRow(Row::fromValues(['Data', 'Tipo', 'Descrição', 'Conta', 'Movimento', 'Valor']));

        foreach ($report->rows as $row) {
            /** @var IncomeExpenseReportRow $row */
            $writer->addRow(Row::fromValues([
                $row->occurredAtLocal,
                $row->typeLabel,
                $row->description,
                $row->accountName,
                $row->directionLabel,
                $this->brl($row->amount),
            ]));
        }

        $writer->close();

        return $path;
    }

    protected function filename(Company $company, IncomeExpenseReport $report, string $extension): string
    {
        $slug = (string) ($company->slug ?: 'empresa');

        return "receitas-gastos-{$slug}-{$report->periodStartFile}-{$report->periodEndFile}.{$extension}";
    }

    protected function brl(string $amount): string
    {
        return 'R$ '.number_format((float) $amount, 2, ',', '.');
    }
}
