/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */

import 'package:flutter/material.dart';
import 'package:fl_chart/fl_chart.dart';
import 'package:get/get.dart';
import 'report_controller.dart';

/// M6d 报表页：用户/支付/提现 3 Tab + 日期区间 + 趋势图 + 明细表 + Excel 导出
class ReportPage extends GetView<ReportController> {
  const ReportPage({super.key});

  @override
  Widget build(BuildContext context) {
    if (!Get.isRegistered<ReportController>()) {
      Get.put(ReportController(), permanent: false);
    }
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        _buildHeader(context),
        const SizedBox(height: 16),
        Expanded(child: _buildBody(context)),
      ],
    );
  }

  Widget _buildHeader(BuildContext context) {
    return Wrap(
      crossAxisAlignment: WrapCrossAlignment.center,
      spacing: 8,
      runSpacing: 8,
      children: [
        const Text('报表', style: TextStyle(fontSize: 20, fontWeight: FontWeight.bold)),
        const SizedBox(width: 4),
        ...ReportController.tabs.map((tab) => Obx(() {
          final selected = controller.activeTab.value == tab;
          return ChoiceChip(
            label: Text(switch (tab) {
              'users' => '用户',
              'payments' => '支付',
              _ => '提现',
            }),
            selected: selected,
            onSelected: (_) {
              if (!selected) controller.activeTab.value = tab;
              controller.load();
            },
          );
        })),
        Obx(() => _dateButton(context, controller.startDate.value, isStart: true)),
        Obx(() => _dateButton(context, controller.endDate.value, isStart: false)),
        IconButton(icon: const Icon(Icons.refresh), tooltip: '刷新', onPressed: () => controller.load()),
        Obx(() => ElevatedButton.icon(
              icon: const Icon(Icons.download, size: 18),
              label: const Text('导出 Excel'),
              onPressed: controller.isLoading.value ? null : () => controller.exportExcel(),
            )),
      ],
    );
  }

  Widget _dateButton(BuildContext context, DateTime value, {required bool isStart}) {
    return OutlinedButton.icon(
      icon: const Icon(Icons.calendar_today, size: 16),
      label: Text(controller.fmt(value)),
      onPressed: () async {
        final picked = await showDatePicker(
          context: context,
          initialDate: value,
          firstDate: DateTime.now().subtract(const Duration(days: 366)),
          lastDate: DateTime.now(),
        );
        if (picked == null) return;
        if (isStart) {
          if (picked.isAfter(controller.endDate.value)) {
            Get.snackbar('提示', '开始日期不得晚于结束日期');
            return;
          }
          controller.startDate.value = picked;
        } else {
          if (picked.isBefore(controller.startDate.value)) {
            Get.snackbar('提示', '结束日期不得早于开始日期');
            return;
          }
          controller.endDate.value = picked;
        }
        controller.load();
      },
    );
  }

  Widget _buildBody(BuildContext context) {
    return Obx(() {
      if (controller.isLoading.value) {
        return const Center(child: CircularProgressIndicator());
      }
      return SingleChildScrollView(
        padding: const EdgeInsets.only(bottom: 24),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            _buildStatsGrid(context),
            const SizedBox(height: 16),
            _buildTrendCard(context),
            const SizedBox(height: 16),
            Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                if (controller.distribution.isNotEmpty) ...[
                  Expanded(child: _buildDistributionCard(context)),
                  const SizedBox(width: 16),
                ],
                Expanded(flex: 2, child: _buildDailyTable(context)),
              ],
            ),
          ],
        ),
      );
    });
  }

  Widget _buildStatsGrid(BuildContext context) {
    return LayoutBuilder(
      builder: (context, constraints) {
        final crossAxisCount = constraints.maxWidth > 900 ? 4 : 2;
        return GridView.builder(
          shrinkWrap: true,
          physics: const NeverScrollableScrollPhysics(),
          gridDelegate: SliverGridDelegateWithFixedCrossAxisCount(
            crossAxisCount: crossAxisCount,
            mainAxisExtent: 110,
            crossAxisSpacing: 16,
            mainAxisSpacing: 16,
          ),
          itemCount: controller.cards.length,
          itemBuilder: (context, index) {
            final card = controller.cards[index];
            final color = Color(int.parse('0xFF${(card['color'] as String).replaceFirst('#', '')}'));
            return Card(
              child: Padding(
                padding: const EdgeInsets.all(16),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Icon(_getIcon(card['icon'] as String), color: color, size: 20),
                    const Spacer(),
                    Text(card['label'] as String, style: TextStyle(fontSize: 13, color: Colors.grey[600])),
                    const SizedBox(height: 4),
                    Text(card['value'] as String,
                        style: const TextStyle(fontSize: 26, fontWeight: FontWeight.bold)),
                  ],
                ),
              ),
            );
          },
        );
      },
    );
  }

  Widget _buildTrendCard(BuildContext context) {
    final series = controller.trendSpots;
    final isUsers = controller.activeTab.value == 'users';
    return Card(
      child: Padding(
        padding: const EdgeInsets.all(24),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(
              '趋势（${controller.fmt(controller.startDate.value)} ~ ${controller.fmt(controller.endDate.value)}）',
              style: const TextStyle(fontSize: 16, fontWeight: FontWeight.w600),
            ),
            const SizedBox(height: 16),
            SizedBox(
              height: 280,
              child: series.isEmpty
                  ? const Center(child: Text('暂无数据'))
                  : LineChart(LineChartData(
                      gridData: const FlGridData(show: true, drawVerticalLine: false),
                      titlesData: FlTitlesData(
                        bottomTitles: AxisTitles(sideTitles: SideTitles(
                          showTitles: true,
                          interval: (series.first.length / 6).ceilToDouble(),
                          getTitlesWidget: (v, _) {
                            final i = v.toInt();
                            if (i < 0 || i >= controller.daily.length) return const SizedBox.shrink();
                            return Padding(
                              padding: const EdgeInsets.only(top: 6),
                              child: Text(controller.daily[i]['date']?.toString().substring(5) ?? '',
                                  style: const TextStyle(fontSize: 10)),
                            );
                          },
                        )),
                        leftTitles: const AxisTitles(sideTitles: SideTitles(showTitles: true, reservedSize: 44)),
                        topTitles: const AxisTitles(sideTitles: SideTitles(showTitles: false)),
                        rightTitles: const AxisTitles(sideTitles: SideTitles(showTitles: false)),
                      ),
                      borderData: FlBorderData(show: false),
                      lineBarsData: [
                        for (final (i, spots) in series.indexed)
                          LineChartBarData(
                            spots: spots,
                            color: i == 0 ? const Color(0xFF1677FF) : const Color(0xFF52C41A),
                            barWidth: 2,
                            dotData: const FlDotData(show: false),
                            belowBarData: BarAreaData(
                              show: true,
                              color: (i == 0 ? const Color(0xFF1677FF) : const Color(0xFF52C41A))
                                  .withValues(alpha: 0.08),
                            ),
                          ),
                      ],
                    )),
            ),
            if (isUsers) ...[
              const SizedBox(height: 12),
              Row(children: [
                _legend(const Color(0xFF1677FF), '新增用户'),
                const SizedBox(width: 24),
                _legend(const Color(0xFF52C41A), '活跃用户'),
              ]),
            ],
          ],
        ),
      ),
    );
  }

  Widget _buildDistributionCard(BuildContext context) {
    return Card(
      child: Padding(
        padding: const EdgeInsets.all(24),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            const Text('分布', style: TextStyle(fontSize: 16, fontWeight: FontWeight.w600)),
            const SizedBox(height: 16),
            SizedBox(
              height: 160,
              child: PieChart(PieChartData(
                sections: controller.pieSections,
                centerSpaceRadius: 36,
                sectionsSpace: 2,
              )),
            ),
            const SizedBox(height: 12),
            ...controller.distribution.asMap().entries.map((e) => Padding(
                  padding: const EdgeInsets.symmetric(vertical: 2),
                  child: Row(
                    children: [
                      Container(width: 12, height: 12,
                          decoration: BoxDecoration(color: controller.distributionColors[e.key], borderRadius: BorderRadius.circular(2))),
                      const SizedBox(width: 6),
                      Expanded(
                        child: Text('${e.value['name']}  ${e.value['value']}',
                            style: const TextStyle(fontSize: 12), overflow: TextOverflow.ellipsis),
                      ),
                    ],
                  ),
                )),
          ],
        ),
      ),
    );
  }

  Widget _buildDailyTable(BuildContext context) {
    final isUsers = controller.activeTab.value == 'users';
    final isPayments = controller.activeTab.value == 'payments';
    final ctrl = controller;
    return Card(
      child: Padding(
        padding: const EdgeInsets.all(24),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            const Text('按日明细', style: TextStyle(fontSize: 16, fontWeight: FontWeight.w600)),
            const SizedBox(height: 12),
            SingleChildScrollView(
              scrollDirection: Axis.horizontal,
              child: DataTable(
                columns: [
                  const DataColumn(label: Text('日期')),
                  DataColumn(label: Text(isUsers ? '新增用户' : (isPayments ? '订单数' : '笔数'))),
                  DataColumn(label: Text(isUsers ? '活跃用户' : '金额(元)')),
                ],
                rows: ctrl.daily.map((d) {
                  final isAmountCol = !isUsers;
                  return DataRow(cells: [
                    DataCell(Text(d['date']?.toString() ?? '')),
                    DataCell(Text('${d[isUsers ? 'new' : (isPayments ? 'orders' : 'count')] ?? 0}')),
                    DataCell(Text(isAmountCol
                        ? (((d['amount_cents'] as num?) ?? 0) / 100).toStringAsFixed(2)
                        : '${d['active'] ?? 0}')),
                  ]);
                }).toList(),
              ),
            ),
          ],
        ),
      ),
    );
  }

  Widget _legend(Color color, String label) {
    return Row(
      mainAxisSize: MainAxisSize.min,
      children: [
        Container(width: 12, height: 12, decoration: BoxDecoration(color: color, borderRadius: BorderRadius.circular(2))),
        const SizedBox(width: 4),
        Text(label, style: const TextStyle(fontSize: 12)),
      ],
    );
  }

  IconData _getIcon(String name) {
    switch (name) {
      case 'people': return Icons.people;
      case 'person_add': return Icons.person_add;
      case 'bolt': return Icons.bolt;
      case 'payments': return Icons.payments;
      case 'savings': return Icons.savings;
      case 'account_balance_wallet': return Icons.account_balance_wallet;
      case 'money_off': return Icons.money_off;
      default: return Icons.description;
    }
  }
}
