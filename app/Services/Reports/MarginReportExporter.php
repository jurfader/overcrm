<?php

namespace App\Services\Reports;

use App\Models\Setting;
use App\Services\FakturowniaService;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Chart\Chart;
use PhpOffice\PhpSpreadsheet\Chart\DataSeries;
use PhpOffice\PhpSpreadsheet\Chart\DataSeriesValues;
use PhpOffice\PhpSpreadsheet\Chart\Legend as ChartLegend;
use PhpOffice\PhpSpreadsheet\Chart\PlotArea;
use PhpOffice\PhpSpreadsheet\Chart\Title as ChartTitle;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Conditional;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Generuje profesjonalny raport Excel z danymi z Fakturowni.
 * Używa FakturowniaService::getMarginStats + getProductStats — czyli tych samych
 * metryk co Dashboard (z prawdziwą marżą z 'products_margin', nie zerową).
 *
 * Arkusze:
 *   1. Podsumowanie  — KPI + top 5 klientów po marży
 *   2. Per dział     — wszystkie departamenty Fakturowni
 *   3. Top klienci   — top 50 klientów po marży
 *   4. Top produkty  — top 50 produktów po przychodzie
 *   5. Trend dzienny — wykres liniowy w okresie
 *   6. Faktury       — lista wszystkich faktur (raw)
 *
 * Brand: dynamicznie z config('brand.primary_color') + 'brand.secondary_color'.
 * Hex pobrany z brand() helper, zamieniany na format dla PhpSpreadsheet (bez '#').
 * Kompatybilność: Excel desktop, Numbers (macOS), LibreOffice, Google Sheets.
 */
class MarginReportExporter
{
    /** Brand colors czytane z config('brand.*') przez brand() helper, fallback do defaults. */
    private string $colorPrimary;
    private string $colorDark;
    private const COLOR_LIGHT_GRAY  = 'F4F4F4';
    private const COLOR_GREEN       = '10B981';
    private const COLOR_RED         = 'EF4444';
    private const COLOR_HEADER_TEXT = 'FFFFFF';
    private const COLOR_DARK_TEXT   = '1F2937';
    private const COLOR_MUTED       = '6B7280';

    private Spreadsheet $spreadsheet;
    private array $params;
    private string $appName;
    private ?string $logoPath = null;

    public function __construct(private FakturowniaService $fakturownia) {}

    public function generate(array $params): string
    {
        $this->params = $params;
        $this->appName = (string) (Setting::get('app_name', brand('name'), 'core') ?: brand('name'));
        // Konwersja hex z brand (#RRGGBB lub RRGGBB) na format PhpSpreadsheet (RRGGBB bez #)
        $this->colorPrimary = ltrim((string) brand('primary_color', '#E91E8C'), '#');
        $this->colorDark    = ltrim((string) brand('secondary_color', '#9B26D9'), '#');
        $this->logoPath = $this->resolveLogoPath();

        $this->spreadsheet = new Spreadsheet();
        $this->spreadsheet->getProperties()
            ->setCreator($this->appName)
            ->setTitle("Raport marżowości — {$params['date_from']} – {$params['date_to']}")
            ->setSubject('Raport sprzedaży i marżowości')
            ->setCompany($this->appName);

        $data = $this->fetchData();

        $this->buildSummarySheet($data);
        $this->buildDepartmentSheet($data);
        $this->buildTopClientsSheet($data);
        $this->buildTopProductsSheet($data);
        $this->buildDailyTrendSheet($data);
        $this->buildInvoicesSheet($data);

        $this->spreadsheet->setActiveSheetIndex(0);

        $tmpPath = tempnam(sys_get_temp_dir(), 'report_') . '.xlsx';
        $writer = new Xlsx($this->spreadsheet);
        $writer->setIncludeCharts(true);
        $writer->save($tmpPath);

        return $tmpPath;
    }

    /**
     * Pobiera dane z FakturowniaService — używa getMarginStats (prawdziwe marże),
     * getProductStats (top produkty) i fetchInvoicesForPeriod (lista faktur dla raw sheet).
     */
    private function fetchData(): array
    {
        $dateFrom = $this->params['date_from'];
        $dateTo = $this->params['date_to'];
        $deptId = !empty($this->params['department_ids']) ? (int) $this->params['department_ids'][0] : null;

        // Margin stats — prawdziwe marże z products_margin Fakturowni (per klient, per departament, totals)
        $marginStats = $this->fakturownia->getMarginStats('custom', $deptId, $dateFrom, $dateTo);

        // Product stats — top produkty (z batchowanego fetch positions)
        $productStats = $this->fakturownia->getProductStats('custom', $deptId, $dateFrom, $dateTo);

        // Lista faktur dla raw sheet
        $invoices = $this->fakturownia->fetchInvoicesForPeriod('custom', $deptId, $dateFrom, $dateTo);

        // Lookup department name dla raw sheet
        $departments = $this->fakturownia->getDepartments();
        $deptLookup = [];
        foreach ($departments as $d) {
            $id = (int) ($d['id'] ?? 0);
            $deptLookup[$id] = (string) ($d['shortcut'] ?: ($d['name'] ?? ('Dział #' . $id)));
        }

        // Trend dzienny — agreguj z faktur
        $perDay = [];
        foreach ($invoices as $inv) {
            $date = substr((string) ($inv['issue_date'] ?? ''), 0, 10);
            if ($date === '') continue;
            $revenue = (float) ($inv['price_gross'] ?? 0);
            $marginNet = isset($inv['products_margin']) ? (float) $inv['products_margin'] : 0.0;
            if (!isset($perDay[$date])) {
                $perDay[$date] = ['date' => $date, 'count' => 0, 'revenue' => 0.0, 'margin' => 0.0];
            }
            $perDay[$date]['count']++;
            $perDay[$date]['revenue'] += $revenue;
            $perDay[$date]['margin'] += $marginNet;
        }
        ksort($perDay);

        return [
            'marginStats' => $marginStats,
            'productStats' => $productStats,
            'invoices' => $invoices,
            'deptLookup' => $deptLookup,
            'perDay' => array_values($perDay),
        ];
    }

    // ============================================================
    // ARKUSZE
    // ============================================================

    /** Arkusz 1: Cover sheet z brandingiem, KPI, top 5 klientów */
    private function buildSummarySheet(array $data): void
    {
        $sheet = $this->spreadsheet->getActiveSheet();
        $sheet->setTitle('Podsumowanie');
        $sheet->setShowGridlines(false);

        // Logo
        if ($this->logoPath && file_exists($this->logoPath)) {
            try {
                $drawing = new Drawing();
                $drawing->setName('Logo');
                $drawing->setDescription($this->appName);
                $drawing->setPath($this->logoPath);
                $drawing->setHeight(60);
                $drawing->setCoordinates('B2');
                $drawing->setOffsetX(10);
                $drawing->setOffsetY(10);
                $drawing->setWorksheet($sheet);
            } catch (\Throwable $e) {
                // ignore
            }
        }

        // Header
        $sheet->setCellValue('D2', 'RAPORT MARŻOWOŚCI');
        $sheet->mergeCells('D2:K2');
        $sheet->getStyle('D2')->applyFromArray([
            'font' => ['bold' => true, 'size' => 22, 'color' => ['rgb' => self::COLOR_DARK_TEXT]],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
        ]);

        $sheet->setCellValue('D3', $this->appName);
        $sheet->mergeCells('D3:K3');
        $sheet->getStyle('D3')->applyFromArray([
            'font' => ['size' => 14, 'color' => ['rgb' => $this->colorPrimary]],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
        ]);

        $sheet->setCellValue('D4', sprintf(
            'Okres: %s – %s · %s',
            $this->formatDatePl($this->params['date_from']),
            $this->formatDatePl($this->params['date_to']),
            $this->params['department_filter_label'] ?? 'Wszyscy handlowcy'
        ));
        $sheet->mergeCells('D4:K4');
        $sheet->getStyle('D4')->applyFromArray([
            'font' => ['size' => 11, 'color' => ['rgb' => self::COLOR_MUTED]],
        ]);

        $sheet->setCellValue('D5', 'Wygenerowano: ' . now()->format('d.m.Y H:i'));
        $sheet->mergeCells('D5:K5');
        $sheet->getStyle('D5')->applyFromArray([
            'font' => ['size' => 9, 'italic' => true, 'color' => ['rgb' => '9CA3AF']],
        ]);

        // Pasek brandingowy
        $sheet->getStyle('B7:K7')->applyFromArray([
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $this->colorPrimary]],
        ]);
        $sheet->getRowDimension(7)->setRowHeight(4);

        // KPI cards — 6 kafelków w dwóch rzędach
        $totals = $data['marginStats']['totals'] ?? [];
        $clientCount = $totals['client_count'] ?? 0;
        $invoiceCount = $totals['invoice_count'] ?? 0;
        $avgInvoice = $invoiceCount > 0 ? ($totals['revenue'] ?? 0) / $invoiceCount : 0;
        $avgPerClient = $clientCount > 0 ? ($totals['revenue'] ?? 0) / $clientCount : 0;

        $kpis = [
            // Rząd 1
            ['label' => 'PRZYCHÓD NETTO',  'value' => $totals['revenue'] ?? 0,         'fmt' => 'pln', 'col' => 'B'],
            ['label' => 'PRZYCHÓD BRUTTO', 'value' => $totals['revenue_gross'] ?? 0,   'fmt' => 'pln', 'col' => 'E'],
            ['label' => 'KOSZTY',          'value' => $totals['cost'] ?? 0,            'fmt' => 'pln', 'col' => 'H'],
            // Rząd 2
            ['label' => 'MARŻA',           'value' => $totals['margin'] ?? 0,          'fmt' => 'pln', 'col' => 'B', 'row' => 13],
            ['label' => 'MARŻA %',         'value' => $totals['margin_percent'] ?? 0,  'fmt' => 'pct', 'col' => 'E', 'row' => 13],
            ['label' => 'KLIENTÓW',        'value' => $clientCount,                    'fmt' => 'int', 'col' => 'H', 'row' => 13],
        ];

        foreach ($kpis as $kpi) {
            $col = $kpi['col'];
            $startRow = $kpi['row'] ?? 9;
            $endCol = chr(ord($col) + 2);
            $valueRow = $startRow + 1;

            $sheet->setCellValue("{$col}{$startRow}", $kpi['label']);
            $sheet->mergeCells("{$col}{$startRow}:{$endCol}{$startRow}");
            $sheet->getStyle("{$col}{$startRow}")->applyFromArray([
                'font' => ['size' => 9, 'bold' => true, 'color' => ['rgb' => self::COLOR_HEADER_TEXT]],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $this->colorDark]],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
            ]);
            $sheet->getRowDimension($startRow)->setRowHeight(20);

            $sheet->setCellValue("{$col}{$valueRow}", $kpi['value']);
            $sheet->mergeCells("{$col}{$valueRow}:{$endCol}{$valueRow}");
            $style = $sheet->getStyle("{$col}{$valueRow}");
            $style->applyFromArray([
                'font' => ['size' => 18, 'bold' => true, 'color' => ['rgb' => self::COLOR_DARK_TEXT]],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => self::COLOR_LIGHT_GRAY]],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
            ]);
            if ($kpi['fmt'] === 'pln') {
                $style->getNumberFormat()->setFormatCode('# ##0,00" zł"');
            } elseif ($kpi['fmt'] === 'pct') {
                $style->getNumberFormat()->setFormatCode('0,0"%"');
            } else {
                $style->getNumberFormat()->setFormatCode('# ##0');
            }
            $sheet->getRowDimension($valueRow)->setRowHeight(34);
        }

        // Korekty (jeśli są)
        $regularCount = $totals['regular_count'] ?? 0;
        $corrCount = $totals['correction_count'] ?? 0;
        if ($corrCount > 0) {
            $sheet->setCellValue('B16', sprintf(
                'ℹ Faktury regularne: %d · Korekty: %d (netto: %s zł, brutto: %s zł)',
                $regularCount,
                $corrCount,
                number_format($totals['correction_net'] ?? 0, 2, ',', ' '),
                number_format($totals['correction_gross'] ?? 0, 2, ',', ' ')
            ));
            $sheet->mergeCells('B16:K16');
            $sheet->getStyle('B16')->applyFromArray([
                'font' => ['size' => 10, 'italic' => true, 'color' => ['rgb' => self::COLOR_MUTED]],
            ]);
        }

        // Top 5 klientów po marży
        $topClients = array_slice($data['marginStats']['topClients'] ?? [], 0, 5);
        $startRow = 18;
        $sheet->setCellValue("B{$startRow}", 'TOP 5 KLIENTÓW PO MARŻY');
        $sheet->mergeCells("B{$startRow}:F{$startRow}");
        $sheet->getStyle("B{$startRow}")->applyFromArray([
            'font' => ['size' => 12, 'bold' => true, 'color' => ['rgb' => self::COLOR_DARK_TEXT]],
        ]);

        $headerRow = $startRow + 2;
        $sheet->setCellValue("B{$headerRow}", 'Klient');
        $sheet->setCellValue("C{$headerRow}", 'Faktur');
        $sheet->setCellValue("D{$headerRow}", 'Przychód');
        $sheet->setCellValue("E{$headerRow}", 'Marża');
        $sheet->setCellValue("F{$headerRow}", 'Marża %');
        $sheet->getStyle("B{$headerRow}:F{$headerRow}")->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => self::COLOR_HEADER_TEXT]],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $this->colorDark]],
        ]);

        $row = $headerRow + 1;
        foreach ($topClients as $c) {
            $sheet->setCellValue("B{$row}", $c['name']);
            $sheet->setCellValue("C{$row}", $c['invoice_count']);
            $sheet->setCellValue("D{$row}", $c['revenue']);
            $sheet->setCellValue("E{$row}", $c['margin']);
            $sheet->setCellValue("F{$row}", $c['margin_percent']);
            $sheet->getStyle("D{$row}:E{$row}")->getNumberFormat()->setFormatCode('# ##0,00" zł"');
            $sheet->getStyle("F{$row}")->getNumberFormat()->setFormatCode('0,0"%"');
            $row++;
        }
        // Zebra
        for ($r = $headerRow + 1; $r < $row; $r++) {
            if (($r - $headerRow - 1) % 2 === 1) {
                $sheet->getStyle("B{$r}:F{$r}")->applyFromArray([
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => self::COLOR_LIGHT_GRAY]],
                ]);
            }
        }
        if ($row > $headerRow + 1) {
            $sheet->getStyle("B{$headerRow}:F" . ($row - 1))->getBorders()->getAllBorders()
                ->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('D1D5DB');
        }

        // Wykres top 5 klientów (pionowy słupkowy — działa też w Numbers)
        if (count($topClients) > 0) {
            $endRow = $row - 1;
            $count = count($topClients);

            $dataSeriesLabels = [
                new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, "Podsumowanie!\$E\${$headerRow}", null, 1),
            ];
            $xAxisTickValues = [
                new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, "Podsumowanie!\$B\$" . ($headerRow + 1) . ":\$B\${$endRow}", null, $count),
            ];
            $dataSeriesValues = [
                new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_NUMBER, "Podsumowanie!\$E\$" . ($headerRow + 1) . ":\$E\${$endRow}", null, $count),
            ];

            $series = new DataSeries(
                DataSeries::TYPE_BARCHART,
                DataSeries::GROUPING_STANDARD,
                range(0, count($dataSeriesValues) - 1),
                $dataSeriesLabels,
                $xAxisTickValues,
                $dataSeriesValues
            );

            $plotArea = new PlotArea(null, [$series]);
            $legend = new ChartLegend(ChartLegend::POSITION_BOTTOM, null, false);
            $title = new ChartTitle('Top 5 klientów — marża (PLN)');

            $chart = new Chart('top_clients_chart', $title, $legend, $plotArea, true);
            $chart->setTopLeftPosition("H{$startRow}");
            $chart->setBottomRightPosition('P30');
            $sheet->addChart($chart);
        }

        // Szerokości kolumn
        $sheet->getColumnDimension('A')->setWidth(2);
        $sheet->getColumnDimension('B')->setWidth(28);
        $sheet->getColumnDimension('C')->setWidth(10);
        $sheet->getColumnDimension('D')->setWidth(15);
        $sheet->getColumnDimension('E')->setWidth(15);
        $sheet->getColumnDimension('F')->setWidth(11);
        for ($c = 'G'; $c <= 'P'; $c++) {
            $sheet->getColumnDimension($c)->setWidth(13);
        }

        $sheet->getRowDimension(2)->setRowHeight(26);
        $sheet->getRowDimension(3)->setRowHeight(20);
        $sheet->getRowDimension(4)->setRowHeight(16);
        $sheet->getRowDimension(5)->setRowHeight(14);
    }

    /** Arkusz 2: Per dział (departament/handlowiec) */
    private function buildDepartmentSheet(array $data): void
    {
        $sheet = $this->spreadsheet->createSheet();
        $sheet->setTitle('Per dział');
        $sheet->setShowGridlines(false);

        $sheet->setCellValue('B2', 'WYNIKI PER DZIAŁ / HANDLOWIEC');
        $sheet->mergeCells('B2:H2');
        $sheet->getStyle('B2')->applyFromArray([
            'font' => ['size' => 16, 'bold' => true, 'color' => ['rgb' => self::COLOR_DARK_TEXT]],
        ]);

        $headers = ['Dział', 'Faktur', 'Przychód netto', 'Przychód brutto', 'Koszty', 'Marża zł', 'Marża %'];
        foreach ($headers as $i => $label) {
            $col = chr(ord('B') + $i);
            $sheet->setCellValue("{$col}4", $label);
        }
        $sheet->getStyle('B4:H4')->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => self::COLOR_HEADER_TEXT]],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $this->colorDark]],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getRowDimension(4)->setRowHeight(28);

        $row = 5;
        foreach ($data['marginStats']['departments'] ?? [] as $d) {
            $sheet->setCellValue("B{$row}", $d['name']);
            $sheet->setCellValue("C{$row}", $d['invoice_count']);
            $sheet->setCellValue("D{$row}", $d['revenue']);
            $sheet->setCellValue("E{$row}", $d['revenue_gross']);
            $sheet->setCellValue("F{$row}", $d['cost']);
            $sheet->setCellValue("G{$row}", $d['margin']);
            $sheet->setCellValue("H{$row}", $d['margin_percent']);
            $row++;
        }

        // Sumy
        if ($row > 5) {
            $totalRow = $row;
            $sheet->setCellValue("B{$totalRow}", 'RAZEM');
            $sheet->setCellValue("C{$totalRow}", "=SUM(C5:C" . ($totalRow - 1) . ")");
            $sheet->setCellValue("D{$totalRow}", "=SUM(D5:D" . ($totalRow - 1) . ")");
            $sheet->setCellValue("E{$totalRow}", "=SUM(E5:E" . ($totalRow - 1) . ")");
            $sheet->setCellValue("F{$totalRow}", "=SUM(F5:F" . ($totalRow - 1) . ")");
            $sheet->setCellValue("G{$totalRow}", "=SUM(G5:G" . ($totalRow - 1) . ")");
            $sheet->setCellValue("H{$totalRow}", "=IF(D{$totalRow}>0,G{$totalRow}/D{$totalRow}*100,0)");
            $sheet->getStyle("B{$totalRow}:H{$totalRow}")->applyFromArray([
                'font' => ['bold' => true, 'color' => ['rgb' => self::COLOR_DARK_TEXT]],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $this->colorPrimary]],
            ]);
        }

        $lastRow = max(5, $row);
        $sheet->getStyle("D5:G{$lastRow}")->getNumberFormat()->setFormatCode('# ##0,00" zł"');
        $sheet->getStyle("H5:H{$lastRow}")->getNumberFormat()->setFormatCode('0,0"%"');

        // Conditional formatting marża %
        if ($row > 5) {
            $condGreen = (new Conditional())
                ->setConditionType(Conditional::CONDITION_CELLIS)
                ->setOperatorType(Conditional::OPERATOR_GREATERTHAN)
                ->addCondition('30');
            $condGreen->getStyle()->getFont()->setBold(true)->getColor()->setRGB(self::COLOR_GREEN);

            $condRed = (new Conditional())
                ->setConditionType(Conditional::CONDITION_CELLIS)
                ->setOperatorType(Conditional::OPERATOR_LESSTHAN)
                ->addCondition('15');
            $condRed->getStyle()->getFont()->setBold(true)->getColor()->setRGB(self::COLOR_RED);

            $sheet->getStyle("H5:H" . ($row - 1))->setConditionalStyles([$condGreen, $condRed]);
        }

        // Zebra rows
        for ($r = 5; $r < $row; $r++) {
            if (($r - 5) % 2 === 1) {
                $sheet->getStyle("B{$r}:H{$r}")->applyFromArray([
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => self::COLOR_LIGHT_GRAY]],
                ]);
            }
        }

        if ($row > 5) {
            $sheet->getStyle("B4:H" . $row)->getBorders()->getAllBorders()
                ->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('D1D5DB');
        }

        // Szerokości
        $sheet->getColumnDimension('A')->setWidth(2);
        $sheet->getColumnDimension('B')->setWidth(28);
        $sheet->getColumnDimension('C')->setWidth(10);
        $sheet->getColumnDimension('D')->setWidth(16);
        $sheet->getColumnDimension('E')->setWidth(16);
        $sheet->getColumnDimension('F')->setWidth(14);
        $sheet->getColumnDimension('G')->setWidth(14);
        $sheet->getColumnDimension('H')->setWidth(11);

        // Wykres
        if ($row > 5) {
            $endRow = $row - 1;
            $count = $endRow - 4;

            $dataSeriesLabels = [
                new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, "'Per dział'!\$D\$4", null, 1),
                new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, "'Per dział'!\$G\$4", null, 1),
            ];
            $xAxisTickValues = [
                new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, "'Per dział'!\$B\$5:\$B\${$endRow}", null, $count),
            ];
            $dataSeriesValues = [
                new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_NUMBER, "'Per dział'!\$D\$5:\$D\${$endRow}", null, $count),
                new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_NUMBER, "'Per dział'!\$G\$5:\$G\${$endRow}", null, $count),
            ];

            $series = new DataSeries(
                DataSeries::TYPE_BARCHART,
                DataSeries::GROUPING_CLUSTERED,
                range(0, count($dataSeriesValues) - 1),
                $dataSeriesLabels,
                $xAxisTickValues,
                $dataSeriesValues
            );
            $plotArea = new PlotArea(null, [$series]);
            $chart = new Chart(
                'dept_chart',
                new ChartTitle('Przychód netto i marża per dział'),
                new ChartLegend(ChartLegend::POSITION_BOTTOM, null, false),
                $plotArea,
                true
            );
            $chart->setTopLeftPosition('J4');
            $chart->setBottomRightPosition('Q22');
            $sheet->addChart($chart);
        }
    }

    /** Arkusz 3: Top 50 klientów po marży */
    private function buildTopClientsSheet(array $data): void
    {
        $sheet = $this->spreadsheet->createSheet();
        $sheet->setTitle('Top klienci');
        $sheet->setShowGridlines(false);

        $sheet->setCellValue('B2', 'TOP 50 KLIENTÓW PO MARŻY');
        $sheet->mergeCells('B2:I2');
        $sheet->getStyle('B2')->applyFromArray([
            'font' => ['size' => 16, 'bold' => true, 'color' => ['rgb' => self::COLOR_DARK_TEXT]],
        ]);

        $headers = ['Lp.', 'Klient', 'NIP', 'Faktur', 'Przychód netto', 'Koszty', 'Marża zł', 'Marża %'];
        foreach ($headers as $i => $label) {
            $col = chr(ord('B') + $i);
            $sheet->setCellValue("{$col}4", $label);
        }
        $sheet->getStyle('B4:I4')->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => self::COLOR_HEADER_TEXT]],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $this->colorDark]],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
        ]);
        $sheet->getRowDimension(4)->setRowHeight(28);

        $clients = array_slice($data['marginStats']['allClients'] ?? [], 0, 50);
        $row = 5;
        $lp = 1;
        foreach ($clients as $c) {
            $sheet->setCellValue("B{$row}", $lp++);
            $sheet->setCellValue("C{$row}", $c['name']);
            $sheet->setCellValueExplicit("D{$row}", (string) ($c['nip'] ?? ''), DataType::TYPE_STRING);
            $sheet->setCellValue("E{$row}", $c['invoice_count']);
            $sheet->setCellValue("F{$row}", $c['revenue']);
            $sheet->setCellValue("G{$row}", $c['cost']);
            $sheet->setCellValue("H{$row}", $c['margin']);
            $sheet->setCellValue("I{$row}", $c['margin_percent']);
            $row++;
        }

        $lastRow = max(5, $row - 1);
        $sheet->getStyle("F5:H{$lastRow}")->getNumberFormat()->setFormatCode('# ##0,00" zł"');
        $sheet->getStyle("I5:I{$lastRow}")->getNumberFormat()->setFormatCode('0,0"%"');

        if ($row > 5) {
            // Conditional formatting
            $condGreen = (new Conditional())
                ->setConditionType(Conditional::CONDITION_CELLIS)
                ->setOperatorType(Conditional::OPERATOR_GREATERTHAN)
                ->addCondition('30');
            $condGreen->getStyle()->getFont()->setBold(true)->getColor()->setRGB(self::COLOR_GREEN);

            $condRed = (new Conditional())
                ->setConditionType(Conditional::CONDITION_CELLIS)
                ->setOperatorType(Conditional::OPERATOR_LESSTHAN)
                ->addCondition('15');
            $condRed->getStyle()->getFont()->setBold(true)->getColor()->setRGB(self::COLOR_RED);

            $sheet->getStyle("I5:I" . ($row - 1))->setConditionalStyles([$condGreen, $condRed]);

            for ($r = 5; $r < $row; $r++) {
                if (($r - 5) % 2 === 1) {
                    $sheet->getStyle("B{$r}:I{$r}")->applyFromArray([
                        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => self::COLOR_LIGHT_GRAY]],
                    ]);
                }
            }
            $sheet->getStyle("B4:I" . ($row - 1))->getBorders()->getAllBorders()
                ->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('D1D5DB');

            $sheet->setAutoFilter("B4:I" . ($row - 1));
            $sheet->freezePane('A5');
        }

        // Szerokości
        $sheet->getColumnDimension('A')->setWidth(2);
        $sheet->getColumnDimension('B')->setWidth(6);
        $sheet->getColumnDimension('C')->setWidth(40);
        $sheet->getColumnDimension('D')->setWidth(14);
        $sheet->getColumnDimension('E')->setWidth(10);
        $sheet->getColumnDimension('F')->setWidth(15);
        $sheet->getColumnDimension('G')->setWidth(13);
        $sheet->getColumnDimension('H')->setWidth(15);
        $sheet->getColumnDimension('I')->setWidth(11);
    }

    /** Arkusz 4: Top produkty (z getProductStats) */
    private function buildTopProductsSheet(array $data): void
    {
        $sheet = $this->spreadsheet->createSheet();
        $sheet->setTitle('Top produkty');
        $sheet->setShowGridlines(false);

        $sheet->setCellValue('B2', 'TOP 50 PRODUKTÓW PO PRZYCHODZIE');
        $sheet->mergeCells('B2:F2');
        $sheet->getStyle('B2')->applyFromArray([
            'font' => ['size' => 16, 'bold' => true, 'color' => ['rgb' => self::COLOR_DARK_TEXT]],
        ]);

        // Info o tym ile faktur zostało analizowanych
        $analyzedCount = $data['productStats']['analyzed_count'] ?? 0;
        $totalCount = $data['productStats']['total_count'] ?? 0;
        if ($totalCount > 0 && $analyzedCount < $totalCount) {
            $sheet->setCellValue('B3', sprintf(
                'ℹ Analiza pozycji z %d największych faktur (z %d w okresie). Pozostałe są pominięte dla wydajności.',
                $analyzedCount,
                $totalCount
            ));
            $sheet->mergeCells('B3:H3');
            $sheet->getStyle('B3')->applyFromArray([
                'font' => ['size' => 10, 'italic' => true, 'color' => ['rgb' => self::COLOR_MUTED]],
            ]);
        }

        $headers = ['Lp.', 'Produkt', 'Sztuk', 'Faktur', 'Przychód netto'];
        foreach ($headers as $i => $label) {
            $col = chr(ord('B') + $i);
            $sheet->setCellValue("{$col}5", $label);
        }
        $sheet->getStyle('B5:F5')->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => self::COLOR_HEADER_TEXT]],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $this->colorDark]],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
        ]);
        $sheet->getRowDimension(5)->setRowHeight(28);

        $products = $data['productStats']['products'] ?? [];
        $row = 6;
        $lp = 1;
        foreach ($products as $p) {
            $sheet->setCellValue("B{$row}", $lp++);
            $sheet->setCellValue("C{$row}", $p['name']);
            $sheet->setCellValue("D{$row}", $p['quantity']);
            $sheet->setCellValue("E{$row}", $p['invoice_count']);
            $sheet->setCellValue("F{$row}", $p['revenue']);
            $row++;
        }

        $lastRow = max(6, $row - 1);
        $sheet->getStyle("D6:D{$lastRow}")->getNumberFormat()->setFormatCode('# ##0,00');
        $sheet->getStyle("F6:F{$lastRow}")->getNumberFormat()->setFormatCode('# ##0,00" zł"');

        if ($row > 6) {
            for ($r = 6; $r < $row; $r++) {
                if (($r - 6) % 2 === 1) {
                    $sheet->getStyle("B{$r}:F{$r}")->applyFromArray([
                        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => self::COLOR_LIGHT_GRAY]],
                    ]);
                }
            }
            $sheet->getStyle("B5:F" . ($row - 1))->getBorders()->getAllBorders()
                ->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('D1D5DB');
            $sheet->setAutoFilter("B5:F" . ($row - 1));
            $sheet->freezePane('A6');
        }

        $sheet->getColumnDimension('A')->setWidth(2);
        $sheet->getColumnDimension('B')->setWidth(6);
        $sheet->getColumnDimension('C')->setWidth(50);
        $sheet->getColumnDimension('D')->setWidth(12);
        $sheet->getColumnDimension('E')->setWidth(10);
        $sheet->getColumnDimension('F')->setWidth(16);

        // Wykres top 10
        if (count($products) > 0) {
            $top10 = min(10, count($products));
            $endRow = 5 + $top10;
            $dataSeriesLabels = [
                new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, "'Top produkty'!\$F\$5", null, 1),
            ];
            $xAxisTickValues = [
                new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, "'Top produkty'!\$C\$6:\$C\${$endRow}", null, $top10),
            ];
            $dataSeriesValues = [
                new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_NUMBER, "'Top produkty'!\$F\$6:\$F\${$endRow}", null, $top10),
            ];

            $series = new DataSeries(
                DataSeries::TYPE_BARCHART,
                DataSeries::GROUPING_STANDARD,
                range(0, count($dataSeriesValues) - 1),
                $dataSeriesLabels,
                $xAxisTickValues,
                $dataSeriesValues
            );
            $plotArea = new PlotArea(null, [$series]);
            $chart = new Chart(
                'products_chart',
                new ChartTitle('Top 10 produktów — przychód'),
                new ChartLegend(ChartLegend::POSITION_BOTTOM, null, false),
                $plotArea,
                true
            );
            $chart->setTopLeftPosition('H5');
            $chart->setBottomRightPosition('P25');
            $sheet->addChart($chart);
        }
    }

    /** Arkusz 5: Trend dzienny */
    private function buildDailyTrendSheet(array $data): void
    {
        $sheet = $this->spreadsheet->createSheet();
        $sheet->setTitle('Trend dzienny');
        $sheet->setShowGridlines(false);

        $sheet->setCellValue('B2', 'TREND DZIENNY');
        $sheet->mergeCells('B2:E2');
        $sheet->getStyle('B2')->applyFromArray([
            'font' => ['size' => 16, 'bold' => true, 'color' => ['rgb' => self::COLOR_DARK_TEXT]],
        ]);

        $headers = ['Data', 'Faktur', 'Przychód brutto', 'Marża'];
        foreach ($headers as $i => $label) {
            $col = chr(ord('B') + $i);
            $sheet->setCellValue("{$col}4", $label);
        }
        $sheet->getStyle('B4:E4')->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => self::COLOR_HEADER_TEXT]],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $this->colorDark]],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
        ]);
        $sheet->getRowDimension(4)->setRowHeight(26);

        $row = 5;
        foreach ($data['perDay'] as $day) {
            $sheet->setCellValue("B{$row}", $day['date']);
            $sheet->getStyle("B{$row}")->getNumberFormat()->setFormatCode('yyyy-mm-dd');
            $sheet->setCellValue("C{$row}", $day['count']);
            $sheet->setCellValue("D{$row}", $day['revenue']);
            $sheet->setCellValue("E{$row}", $day['margin']);
            $row++;
        }

        $lastRow = max(5, $row - 1);
        $sheet->getStyle("D5:E{$lastRow}")->getNumberFormat()->setFormatCode('# ##0,00" zł"');

        if ($row > 5) {
            for ($r = 5; $r < $row; $r++) {
                if (($r - 5) % 2 === 1) {
                    $sheet->getStyle("B{$r}:E{$r}")->applyFromArray([
                        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => self::COLOR_LIGHT_GRAY]],
                    ]);
                }
            }
            $sheet->getStyle("B4:E" . ($row - 1))->getBorders()->getAllBorders()
                ->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('D1D5DB');
        }

        $sheet->getColumnDimension('A')->setWidth(2);
        $sheet->getColumnDimension('B')->setWidth(14);
        $sheet->getColumnDimension('C')->setWidth(10);
        $sheet->getColumnDimension('D')->setWidth(17);
        $sheet->getColumnDimension('E')->setWidth(15);

        // Wykres liniowy
        if ($row > 5) {
            $endRow = $row - 1;
            $count = $endRow - 4;

            $dataSeriesLabels = [
                new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, "'Trend dzienny'!\$D\$4", null, 1),
                new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, "'Trend dzienny'!\$E\$4", null, 1),
            ];
            $xAxisTickValues = [
                new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, "'Trend dzienny'!\$B\$5:\$B\${$endRow}", null, $count),
            ];
            $dataSeriesValues = [
                new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_NUMBER, "'Trend dzienny'!\$D\$5:\$D\${$endRow}", null, $count),
                new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_NUMBER, "'Trend dzienny'!\$E\$5:\$E\${$endRow}", null, $count),
            ];

            $series = new DataSeries(
                DataSeries::TYPE_LINECHART,
                DataSeries::GROUPING_STANDARD,
                range(0, count($dataSeriesValues) - 1),
                $dataSeriesLabels,
                $xAxisTickValues,
                $dataSeriesValues
            );
            $plotArea = new PlotArea(null, [$series]);
            $chart = new Chart(
                'trend_chart',
                new ChartTitle('Trend przychodu i marży w okresie'),
                new ChartLegend(ChartLegend::POSITION_BOTTOM, null, false),
                $plotArea,
                true
            );
            $chart->setTopLeftPosition('G4');
            $chart->setBottomRightPosition('P25');
            $sheet->addChart($chart);
        }
    }

    /** Arkusz 6: Lista wszystkich faktur (raw) */
    private function buildInvoicesSheet(array $data): void
    {
        $sheet = $this->spreadsheet->createSheet();
        $sheet->setTitle('Faktury');
        $sheet->setShowGridlines(false);

        $sheet->setCellValue('B2', 'LISTA FAKTUR');
        $sheet->mergeCells('B2:J2');
        $sheet->getStyle('B2')->applyFromArray([
            'font' => ['size' => 16, 'bold' => true, 'color' => ['rgb' => self::COLOR_DARK_TEXT]],
        ]);

        $headers = ['Numer', 'Data', 'Klient', 'NIP', 'Dział', 'Netto', 'Brutto', 'Marża', 'Typ'];
        foreach ($headers as $i => $label) {
            $col = chr(ord('B') + $i);
            $sheet->setCellValue("{$col}4", $label);
        }
        $sheet->getStyle('B4:J4')->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => self::COLOR_HEADER_TEXT]],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $this->colorDark]],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
        ]);
        $sheet->getRowDimension(4)->setRowHeight(26);

        $row = 5;
        foreach ($data['invoices'] as $inv) {
            $deptId = (int) ($inv['department_id'] ?? 0);
            $deptName = $data['deptLookup'][$deptId] ?? '—';
            $kind = $inv['kind'] ?? 'vat';
            $kindLabel = match ($kind) {
                'correction' => 'Korekta',
                'correction_note' => 'Nota kor.',
                'proforma' => 'Proforma',
                'estimate' => 'Oferta',
                default => 'Faktura VAT',
            };
            $marginNet = isset($inv['products_margin']) ? (float) $inv['products_margin'] : 0.0;

            $sheet->setCellValueExplicit("B{$row}", (string) ($inv['number'] ?? $inv['id'] ?? ''), DataType::TYPE_STRING);
            $sheet->setCellValue("C{$row}", substr((string) ($inv['issue_date'] ?? ''), 0, 10));
            $sheet->getStyle("C{$row}")->getNumberFormat()->setFormatCode('yyyy-mm-dd');
            $sheet->setCellValue("D{$row}", $inv['buyer_name'] ?? '');
            $sheet->setCellValueExplicit("E{$row}", (string) ($inv['buyer_tax_no'] ?? ''), DataType::TYPE_STRING);
            $sheet->setCellValue("F{$row}", $deptName);
            $sheet->setCellValue("G{$row}", (float) ($inv['price_net'] ?? 0));
            $sheet->setCellValue("H{$row}", (float) ($inv['price_gross'] ?? 0));
            $sheet->setCellValue("I{$row}", $marginNet);
            $sheet->setCellValue("J{$row}", $kindLabel);
            $row++;
        }

        $lastRow = max(5, $row - 1);
        $sheet->getStyle("G5:I{$lastRow}")->getNumberFormat()->setFormatCode('# ##0,00" zł"');

        if ($row > 5) {
            for ($r = 5; $r < $row; $r++) {
                if (($r - 5) % 2 === 1) {
                    $sheet->getStyle("B{$r}:J{$r}")->applyFromArray([
                        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => self::COLOR_LIGHT_GRAY]],
                    ]);
                }
            }
            $sheet->getStyle("B4:J" . ($row - 1))->getBorders()->getAllBorders()
                ->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('D1D5DB');
            $sheet->setAutoFilter("B4:J" . ($row - 1));
            $sheet->freezePane('A5');
        }

        $sheet->getColumnDimension('A')->setWidth(2);
        $sheet->getColumnDimension('B')->setWidth(20);
        $sheet->getColumnDimension('C')->setWidth(12);
        $sheet->getColumnDimension('D')->setWidth(35);
        $sheet->getColumnDimension('E')->setWidth(14);
        $sheet->getColumnDimension('F')->setWidth(18);
        $sheet->getColumnDimension('G')->setWidth(14);
        $sheet->getColumnDimension('H')->setWidth(14);
        $sheet->getColumnDimension('I')->setWidth(14);
        $sheet->getColumnDimension('J')->setWidth(13);
    }

    // ============================================================
    // HELPERY
    // ============================================================

    private function resolveLogoPath(): ?string
    {
        $url = Setting::get('app_logo', null, 'core');
        if (!$url) {
            $defaultPath = public_path('images/logo.png');
            return file_exists($defaultPath) ? $defaultPath : null;
        }

        // Absolute URL → spróbuj zlokalizować w public/
        if (preg_match('#^https?://[^/]+(/.+)$#', $url, $m)) {
            $rel = ltrim($m[1], '/');
            $local = public_path($rel);
            if (file_exists($local)) return $local;
        }

        // Relative path
        $local = public_path(ltrim($url, '/'));
        if (file_exists($local)) return $local;

        $storagePath = storage_path('app/public/' . ltrim($url, '/'));
        if (file_exists($storagePath)) return $storagePath;

        return null;
    }

    private function formatDatePl(string $date): string
    {
        try {
            return \Carbon\Carbon::parse($date)->format('d.m.Y');
        } catch (\Throwable $e) {
            return $date;
        }
    }
}
