/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */

import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'withdrawal_controller.dart';

class WithdrawalPage extends GetView<WithdrawalController> {
  const WithdrawalPage({super.key});

  @override
  Widget build(BuildContext context) {
    if (!Get.isRegistered<WithdrawalController>()) {
      Get.put(WithdrawalController(), permanent: false);
    }
    final ctrl = controller;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Wrap(
          crossAxisAlignment: WrapCrossAlignment.center,
          spacing: 8,
          children: [
            const Text('提现单', style: TextStyle(fontSize: 20, fontWeight: FontWeight.bold)),
            IconButton(icon: const Icon(Icons.refresh), tooltip: '刷新', onPressed: () => ctrl.loadWithdrawals(reset: true)),
          ],
        ),
        const SizedBox(height: 12),
        Obx(() => Row(
          children: [
            ChoiceChip(label: const Text('全部'), selected: ctrl.statusFilter.value == null, onSelected: (_) => ctrl.filterByStatus(null)),
            const SizedBox(width: 4),
            ChoiceChip(label: const Text('待处理'), selected: ctrl.statusFilter.value == 'pending', onSelected: (_) => ctrl.filterByStatus('pending')),
            const SizedBox(width: 4),
            ChoiceChip(label: const Text('已成功'), selected: ctrl.statusFilter.value == 'succeeded', onSelected: (_) => ctrl.filterByStatus('succeeded')),
            const SizedBox(width: 4),
            ChoiceChip(label: const Text('已失败'), selected: ctrl.statusFilter.value == 'failed', onSelected: (_) => ctrl.filterByStatus('failed')),
            const SizedBox(width: 4),
            ChoiceChip(label: const Text('已取消'), selected: ctrl.statusFilter.value == 'cancelled', onSelected: (_) => ctrl.filterByStatus('cancelled')),
          ],
        )),
        const SizedBox(height: 12),
        Expanded(
          child: Obx(() {
            if (ctrl.isLoading.value) return const Center(child: CircularProgressIndicator());
            if (ctrl.withdrawals.isEmpty) return const Center(child: Text('暂无数据'));

            return SingleChildScrollView(
              child: SingleChildScrollView(
                scrollDirection: Axis.horizontal,
                child: DataTable(
                  columns: const [
                    DataColumn(label: Text('ID')),
                    DataColumn(label: Text('用户')),
                    DataColumn(label: Text('渠道')),
                    DataColumn(label: Text('币数')),
                    DataColumn(label: Text('币种')),
                    DataColumn(label: Text('状态')),
                    DataColumn(label: Text('原因')),
                    DataColumn(label: Text('创建时间')),
                    DataColumn(label: Text('操作')),
                  ],
                  rows: ctrl.withdrawals.map((w) {
                    final pending = w['status'] == 'pending';
                    return DataRow(
                      onSelectChanged: (_) => _showDetail(context, w),
                      cells: [
                        DataCell(Text(w['id'].toString())),
                        DataCell(Text('${w['user_id']} ${w['user_email'] ?? ''}'.trim())),
                        DataCell(Text(w['platform'] ?? '')),
                        DataCell(Text(w['coins'].toString())),
                        DataCell(Text(w['currency'] ?? '')),
                        DataCell(Chip(
                          label: Text(w['status'] ?? ''),
                          color: WidgetStatePropertyAll(w['status'] == 'succeeded' ? Colors.green.shade50 : (w['status'] == 'failed' ? Colors.red.shade50 : Colors.orange.shade50)),
                        )),
                        DataCell(Text((w['reason'] ?? '').toString().isEmpty ? '-' : w['reason'].toString())),
                        DataCell(Text((w['created_at'] ?? '-').toString())),
                        DataCell(pending
                            ? TextButton(
                                onPressed: () => _showMarkDialog(context, ctrl, w),
                                child: const Text('标记已处理'),
                              )
                            : const Text('-')),
                      ],
                    );
                  }).toList(),
                ),
              ),
            );
          }),
        ),
        const SizedBox(height: 8),
        Obx(() => Row(
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            IconButton(onPressed: ctrl.prevPage, icon: const Icon(Icons.chevron_left)),
            Text('第 ${ctrl.page.value} 页 / 共 ${(ctrl.total.value / ctrl.limit.value).ceil()} 页 (${ctrl.total.value} 条)'),
            IconButton(onPressed: ctrl.nextPage, icon: const Icon(Icons.chevron_right)),
          ],
        )),
      ],
    );
  }

  void _showDetail(BuildContext context, dynamic w) {
    showDialog(
      context: context,
      builder: (_) => AlertDialog(
        title: Text('提现详情 #${w['id']}'),
        content: SizedBox(
          width: 480,
          child: SingleChildScrollView(
            child: Column(
              mainAxisSize: MainAxisSize.min,
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text('用户: ${w['user_id']} ${w['user_email'] ?? ''}'),
                Text('渠道: ${w['platform']}  状态: ${w['status']}'),
                Text('币数: ${w['coins']} ${w['currency']}'),
                Text('收款账户: ${w['account'] ?? '-'}'),
                Text('client_ref: ${w['client_ref'] ?? '-'}'),
                Text('原因: ${(w['reason'] ?? '').toString().isEmpty ? '-' : w['reason']}'),
                Text('创建: ${w['created_at']}  更新: ${w['updated_at']}'),
              ],
            ),
          ),
        ),
        actions: [
          TextButton(onPressed: () => Navigator.pop(context), child: const Text('关闭')),
        ],
      ),
    );
  }

  void _showMarkDialog(BuildContext context, WithdrawalController ctrl, dynamic w) {
    final id = (w['id'] as num).toInt();
    var status = 'succeeded';
    final reasonCtrl = TextEditingController();
    showDialog(
      context: context,
      builder: (ctx) => StatefulBuilder(
        builder: (ctx, setState) => AlertDialog(
          title: Text('标记提现 #$id'),
          content: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              Row(children: [
                ChoiceChip(label: const Text('已打款成功'), selected: status == 'succeeded', onSelected: (_) => setState(() => status = 'succeeded')),
                const SizedBox(width: 8),
                ChoiceChip(label: const Text('打款失败'), selected: status == 'failed', onSelected: (_) => setState(() => status = 'failed')),
              ]),
              const SizedBox(height: 8),
              if (status == 'failed')
                TextField(controller: reasonCtrl, decoration: const InputDecoration(labelText: '失败原因（必填）', isDense: true)),
            ],
          ),
          actions: [
            TextButton(onPressed: () => Navigator.pop(ctx), child: const Text('取消')),
            ElevatedButton(
              onPressed: () {
                if (status == 'failed' && reasonCtrl.text.trim().isEmpty) {
                  Get.snackbar('提示', '失败原因必填');
                  return;
                }
                Navigator.pop(ctx);
                ctrl.markStatus(id, status, reasonCtrl.text.trim());
              },
              child: const Text('确认'),
            ),
          ],
        ),
      ),
    );
  }
}
