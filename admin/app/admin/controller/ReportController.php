<?php
/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */

declare(strict_types=1);

namespace app\admin\controller;

use app\model\Payment;
use app\model\SocialPost;
use app\model\SocialUser;
use app\model\Withdrawal;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use support\Request;
use support\Response;

/**
 * @Apidoc\Title("报表")
 *
 * M6d 业务报表：社交用户/支付/提现三类聚合（直查 social_ 库），金额均为分。
 * 默认区间近 30 天；start/end 须为 Y-m-d，区间不超过 366 天。
 */
class ReportController extends BaseController
{
    private const TYPES = ['users', 'payments', 'withdrawals'];
    private const MAX_DAYS = 366;

    /**
     * @Apidoc\Title("用户报表")
     * @Apidoc\Group("报表")
     * @Apidoc\Url("/admin/report/users")
     * @Apidoc\Param("start", type="string", require=false, desc="开始日期 Y-m-d，默认近30天")
     * @Apidoc\Param("end", type="string", require=false, desc="结束日期 Y-m-d，默认今天")
     */
    public function users(Request $request): Response
    {
        if ($err = $this->validateRange($request)) {
            return $err;
        }
        [$start, $end] = $this->resolveRange($request);
        return $this->success($this->usersReport($start, $end));
    }

    /**
     * @Apidoc\Title("支付报表")
     * @Apidoc\Group("报表")
     * @Apidoc\Url("/admin/report/payments")
     */
    public function payments(Request $request): Response
    {
        if ($err = $this->validateRange($request)) {
            return $err;
        }
        [$start, $end] = $this->resolveRange($request);
        return $this->success($this->paymentsReport($start, $end));
    }

    /**
     * @Apidoc\Title("提现报表")
     * @Apidoc\Group("报表")
     * @Apidoc\Url("/admin/report/withdrawals")
     */
    public function withdrawals(Request $request): Response
    {
        if ($err = $this->validateRange($request)) {
            return $err;
        }
        [$start, $end] = $this->resolveRange($request);
        return $this->success($this->withdrawalsReport($start, $end));
    }

    /**
     * @Apidoc\Title("导出报表Excel")
     * @Apidoc\Group("报表")
     * @Apidoc\Method("POST")
     * @Apidoc\Url("/admin/report/export")
     * @Apidoc\Param("type", type="string", require=true, desc="users/payments/withdrawals")
     * @Apidoc\Param("start", type="string", require=false, desc="开始日期 Y-m-d，默认近30天")
     * @Apidoc\Param("end", type="string", require=false, desc="结束日期 Y-m-d，默认今天")
     */
    public function export(Request $request): Response
    {
        if ($err = $this->validateRange($request)) {
            return $err;
        }
        $type = (string) $request->post('type', '');
        if (!in_array($type, self::TYPES, true)) {
            return $this->fail('type 取值 users/payments/withdrawals', 400);
        }
        [$start, $end] = $this->resolveRange($request);
        $data = match ($type) {
            'users' => $this->usersReport($start, $end),
            'payments' => $this->paymentsReport($start, $end),
            'withdrawals' => $this->withdrawalsReport($start, $end),
        };
        return $this->downloadExcel($type, $start, $end, $data);
    }

    // ─────────────────────────── 聚合 ───────────────────────────

    private function usersReport(string $start, string $end): array
    {
        $dates = $this->dateSeries($start, $end);
        // 活跃口径：当日发帖的去重用户数（social_users 无登录/活跃字段）
        $dailyNew = SocialUser::whereDate('created_at', '>=', $start)
            ->whereDate('created_at', '<=', $end)
            ->selectRaw('DATE(created_at) as date, COUNT(*) as count')
            ->groupBy('date')->pluck('count', 'date')->all();
        $dailyActive = SocialPost::whereDate('created_at', '>=', $start)
            ->whereDate('created_at', '<=', $end)
            ->selectRaw('DATE(created_at) as date, COUNT(DISTINCT user_id) as count')
            ->groupBy('date')->pluck('count', 'date')->all();

        return [
            'stats' => [
                'total' => (int) SocialUser::count(),
                'new_in_range' => (int) array_sum($dailyNew),
                'active_today' => (int) SocialPost::whereDate('created_at', date('Y-m-d'))->distinct()->count('user_id'),
            ],
            'daily' => array_map(fn (string $d) => [
                'date' => $d,
                'new' => (int) ($dailyNew[$d] ?? 0),
                'active' => (int) ($dailyActive[$d] ?? 0),
            ], $dates),
            'status_distribution' => [
                ['name' => '正常', 'value' => (int) SocialUser::where('status', 1)->count()],
                ['name' => '禁用', 'value' => (int) SocialUser::where('status', 0)->count()],
            ],
        ];
    }

    private function paymentsReport(string $start, string $end): array
    {
        $dates = $this->dateSeries($start, $end);
        $dailyOrders = $this->inRange(Payment::query(), $start, $end)
            ->selectRaw('DATE(created_at) as date, COUNT(*) as count')
            ->groupBy('date')->pluck('count', 'date')->all();
        // 金额口径：仅 succeeded（实际到账），与 stats.succeeded_amount_cents 一致
        $dailyAmount = $this->inRange(Payment::query(), $start, $end)
            ->where('status', 'succeeded')
            ->selectRaw('DATE(created_at) as date, SUM(amount_cents) as total')
            ->groupBy('date')->pluck('total', 'date')->all();

        return [
            'stats' => [
                'orders' => (int) $this->inRange(Payment::query(), $start, $end)->count(),
                'succeeded_amount_cents' => (int) $this->inRange(Payment::query(), $start, $end)
                    ->where('status', 'succeeded')->sum('amount_cents'),
            ],
            'daily' => array_map(fn (string $d) => [
                'date' => $d,
                'orders' => (int) ($dailyOrders[$d] ?? 0),
                'amount_cents' => (int) ($dailyAmount[$d] ?? 0),
            ], $dates),
            'platform_distribution' => $this->distribution(
                $this->inRange(Payment::query(), $start, $end), 'platform'
            ),
            'status_distribution' => $this->distribution(
                $this->inRange(Payment::query(), $start, $end), 'status'
            ),
        ];
    }

    private function withdrawalsReport(string $start, string $end): array
    {
        $dates = $this->dateSeries($start, $end);
        // 提现表无 amount_cents，coins 即金额（分）
        $dailyCount = $this->inRange(Withdrawal::query(), $start, $end)
            ->selectRaw('DATE(created_at) as date, COUNT(*) as count')
            ->groupBy('date')->pluck('count', 'date')->all();
        $dailyAmount = $this->inRange(Withdrawal::query(), $start, $end)
            ->selectRaw('DATE(created_at) as date, SUM(coins) as total')
            ->groupBy('date')->pluck('total', 'date')->all();

        return [
            'stats' => [
                'count' => (int) $this->inRange(Withdrawal::query(), $start, $end)->count(),
                'amount_cents' => (int) $this->inRange(Withdrawal::query(), $start, $end)->sum('coins'),
            ],
            'daily' => array_map(fn (string $d) => [
                'date' => $d,
                'count' => (int) ($dailyCount[$d] ?? 0),
                'amount_cents' => (int) ($dailyAmount[$d] ?? 0),
            ], $dates),
            'status_distribution' => $this->distribution(
                $this->inRange(Withdrawal::query(), $start, $end), 'status'
            ),
        ];
    }

    /** 日期区间过滤 */
    private function inRange($query, string $start, string $end)
    {
        return $query->whereDate('created_at', '>=', $start)->whereDate('created_at', '<=', $end);
    }

    /** 按字段分组计数：[[name, value], ...] */
    private function distribution($query, string $field): array
    {
        return $query->selectRaw("{$field} as name, COUNT(*) as value")
            ->groupBy($field)->get()
            ->map(fn ($row) => ['name' => (string) $row->name, 'value' => (int) $row->value])
            ->values()->toArray();
    }

    private function dateSeries(string $start, string $end): array
    {
        $dates = [];
        for ($t = strtotime($start); $t <= strtotime($end); $t = strtotime('+1 day', $t)) {
            $dates[] = date('Y-m-d', $t);
        }
        return $dates;
    }

    // ─────────────────────────── 区间校验 ───────────────────────────

    /** 校验 start/end（Y-m-d、end>=start、≤366天），非法返回 400 响应 */
    private function validateRange(Request $request): ?Response
    {
        $start = trim((string) $request->get('start', ''));
        $end = trim((string) $request->get('end', ''));
        if ($start !== '' && !self::isValidDate($start)) {
            return $this->fail('start 须为 Y-m-d 格式', 400);
        }
        if ($end !== '' && !self::isValidDate($end)) {
            return $this->fail('end 须为 Y-m-d 格式', 400);
        }
        if ($start !== '' && $end !== '' && strtotime($end) < strtotime($start)) {
            return $this->fail('end 不得早于 start', 400);
        }
        if ($start !== '' && $end !== '' && strtotime($end) - strtotime($start) > self::MAX_DAYS * 86400) {
            return $this->fail("查询区间不得超过 {$this->MAX_DAYS} 天", 400);
        }
        return null;
    }

    /** 解析区间：缺省近 30 天 */
    private function resolveRange(Request $request): array
    {
        $start = trim((string) $request->get('start', ''));
        $end = trim((string) $request->get('end', ''));
        return [
            $start !== '' ? $start : date('Y-m-d', strtotime('-29 days')),
            $end !== '' ? $end : date('Y-m-d'),
        ];
    }

    private static function isValidDate(string $date): bool
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            return false;
        }
        return checkdate((int) substr($date, 5, 2), (int) substr($date, 8, 2), (int) substr($date, 0, 4));
    }

    // ─────────────────────────── Excel 导出 ───────────────────────────

    private function downloadExcel(string $type, string $start, string $end, array $data): Response
    {
        $meta = [
            'users' => [
                'title' => '用户报表',
                'stats' => ['总用户', '区间新增', '今日活跃'],
                'columns' => ['日期', '新增用户', '活跃用户(发帖)'],
                'daily' => ['new', 'active'],
            ],
            'payments' => [
                'title' => '支付报表',
                'stats' => ['订单数', '成功金额(分)'],
                'columns' => ['日期', '订单数', '成功金额(分)'],
                'daily' => ['orders', 'amount_cents'],
            ],
            'withdrawals' => [
                'title' => '提现报表',
                'stats' => ['笔数', '金额(分)'],
                'columns' => ['日期', '笔数', '金额(分)'],
                'daily' => ['count', 'amount_cents'],
            ],
        ][$type];
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle($meta['title']);

        $headerStyle = [
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1677FF']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
        ];
        $dataStyle = ['borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]]];

        $sheet->setCellValue('A1', "{$meta['title']}（{$start} ~ {$end}）");
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);

        // 汇总区（stats 为关联数组，array_values 按声明顺序取值，与 meta.stats 标签一一对应）
        $row = 2;
        $flat = array_values($data['stats']);
        foreach ($meta['stats'] as $i => $label) {
            $sheet->setCellValue("A{$row}", $label)->getStyle("A{$row}")->getFont()->setBold(true);
            $sheet->setCellValue("B{$row}", $flat[$i] ?? '');
            $sheet->getStyle("A{$row}:B{$row}")->applyFromArray($dataStyle);
            $row++;
        }

        // 明细表头
        $row += 1;
        $headRow = $row;
        foreach ($meta['columns'] as $i => $label) {
            $col = chr(65 + $i);
            $sheet->setCellValue("{$col}{$row}", $label);
            $sheet->getStyle("{$col}{$row}")->applyFromArray($headerStyle);
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }
        // 明细行
        $firstDataRow = $row + 1;
        foreach ($data['daily'] as $item) {
            $row++;
            $sheet->setCellValue("A{$row}", $item['date']);
            foreach ($meta['daily'] as $i => $key) {
                $sheet->setCellValue(chr(66 + $i) . $row, $item[$key] ?? 0);
            }
        }
        $sheet->getStyle("A{$firstDataRow}:C{$row}")->applyFromArray($dataStyle);
        // 合计行
        $row++;
        $sheet->setCellValue("A{$row}", '合计');
        foreach ($meta['daily'] as $i => $key) {
            $total = array_sum(array_column($data['daily'], $key));
            $sheet->setCellValue(chr(66 + $i) . $row, $total);
        }
        $sheet->getStyle("A{$row}:C{$row}")->applyFromArray($dataStyle)->getFont()->setBold(true);

        // 分布区（如平台/状态）
        $distributions = array_filter([
            'platform' => $data['platform_distribution'] ?? [],
            'status' => $data['status_distribution'] ?? [],
        ]);
        foreach ($distributions as $label => $items) {
            $row += 2;
            $sheet->setCellValue("A{$row}", "{$label}分布");
            $sheet->getStyle("A{$row}")->getFont()->setBold(true);
            foreach ($items as $item) {
                $row++;
                $sheet->setCellValue("A{$row}", $item['name']);
                $sheet->setCellValue("B{$row}", $item['value']);
                $sheet->getStyle("A{$row}:B{$row}")->applyFromArray($dataStyle);
            }
        }

        $filename = sprintf('report_%s_%s_%s.xlsx', $type, $start, $end);
        $tmpFile = runtime_path() . '/tmp/' . $filename;
        $dir = dirname($tmpFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        (new Xlsx($spreadsheet))->save($tmpFile);

        return response()->download($tmpFile, $filename);
    }
}
