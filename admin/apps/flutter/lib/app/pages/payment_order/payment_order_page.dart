/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */

import 'dart:convert';
import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'payment_order_controller.dart';

class PaymentOrderPage extends GetView<PaymentOrderController> {
  const PaymentOrderPage({super.key});

  @override
  Widget build(BuildContext context) {
    if (!Get.isRegistered<PaymentOrderController>()) {
      Get.put(PaymentOrderController(), permanent: false);
    }
    final ctrl = controller;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Wrap(
          crossAxisAlignment: WrapCrossAlignment.center,
          spacing: 8,
          children: [
            const Text('支付订单', style: TextStyle(fontSize: 20, fontWeight: FontWeight.bold)),
            IconButton(icon: const Icon(Icons.refresh), tooltip: '刷新', onPressed: () => ctrl.loadOrders(reset: true)),
          ],
        ),
        const SizedBox(height: 12),
        Obx(() => Row(
          children: [
            ChoiceChip(label: const Text('全部'), selected: ctrl.statusFilter.value == null, onSelected: (_) => ctrl.filterByStatus(null)),
            const SizedBox(width: 4),
            ChoiceChip(label: const Text('待支付'), selected: ctrl.statusFilter.value == 'pending', onSelected: (_) => ctrl.filterByStatus('pending')),
            const SizedBox(width: 4),
            ChoiceChip(label: const Text('已成功'), selected: ctrl.statusFilter.value == 'succeeded', onSelected: (_) => ctrl.filterByStatus('succeeded')),
            const SizedBox(width: 4),
            ChoiceChip(label: const Text('已失败'), selected: ctrl.statusFilter.value == 'failed', onSelected: (_) => ctrl.filterByStatus('failed')),
          ],
        )),
        const SizedBox(height: 12),
        Expanded(
          child: Obx(() {
            if (ctrl.isLoading.value) return const Center(child: CircularProgressIndicator());
            if (ctrl.orders.isEmpty) return const Center(child: Text('暂无数据'));

            return SingleChildScrollView(
              child: SingleChildScrollView(
                scrollDirection: Axis.horizontal,
                child: DataTable(
                  columns: const [
                    DataColumn(label: Text('ID')),
                    DataColumn(label: Text('用户')),
                    DataColumn(label: Text('渠道')),
                    DataColumn(label: Text('金额(分)')),
                    DataColumn(label: Text('币种')),
                    DataColumn(label: Text('到账币')),
                    DataColumn(label: Text('状态')),
                    DataColumn(label: Text('交易号')),
                    DataColumn(label: Text('创建时间')),
                  ],
                  rows: ctrl.orders.map((o) {
                    return DataRow(
                      onSelectChanged: (_) => _showDetail(context, o),
                      cells: [
                        DataCell(Text(o['id'].toString())),
                        DataCell(Text('${o['user_id']} ${o['user_email'] ?? ''}'.trim())),
                        DataCell(Text(o['platform'] ?? '')),
                        DataCell(Text(o['amount_cents'].toString())),
                        DataCell(Text(o['currency'] ?? '')),
                        DataCell(Text(o['coins'].toString())),
                        DataCell(Chip(
                          label: Text(o['status'] ?? ''),
                          color: WidgetStatePropertyAll(o['status'] == 'succeeded' ? Colors.green.shade50 : (o['status'] == 'failed' ? Colors.red.shade50 : Colors.orange.shade50)),
                        )),
                        DataCell(Text(o['trade_no'] ?? '-')),
                        DataCell(Text((o['created_at'] ?? '-').toString())),
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

  void _showDetail(BuildContext context, dynamic order) {
    showDialog(
      context: context,
      builder: (_) => AlertDialog(
        title: Text('订单详情 #${order['id']}'),
        content: SizedBox(
          width: 480,
          child: SingleChildScrollView(
            child: Column(
              mainAxisSize: MainAxisSize.min,
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text('用户: ${order['user_id']} ${order['user_email'] ?? ''}'),
                Text('渠道: ${order['platform']}  状态: ${order['status']}'),
                Text('金额: ${order['amount_cents']}${order['currency']} → ${order['coins']} 币'),
                Text('client_ref: ${order['client_ref'] ?? '-'}'),
                Text('交易号: ${order['trade_no'] ?? '-'}'),
                Text('创建: ${order['created_at']}  更新: ${order['updated_at']}'),
                const SizedBox(height: 8),
                const Text('回调原始数据:', style: TextStyle(fontWeight: FontWeight.bold)),
                const SizedBox(height: 4),
                Text(
                  _prettyPayload(order['payload']),
                  style: const TextStyle(fontFamily: 'monospace', fontSize: 12),
                ),
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

  String _prettyPayload(dynamic payload) {
    if (payload == null || payload == '') return '-';
    try {
      return const JsonEncoder.withIndent('  ').convert(jsonDecode(payload as String));
    } catch (_) {
      return payload.toString();
    }
  }
}
