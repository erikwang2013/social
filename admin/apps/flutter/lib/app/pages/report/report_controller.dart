/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */

import 'package:dio/dio.dart';
import 'package:file_saver/file_saver.dart';
import 'package:flutter/material.dart';
import 'package:fl_chart/fl_chart.dart';
import 'package:get/get.dart';
import '../../services/api_service.dart';

/// M6d 报表页控制器：用户/支付/提现三类，支持日期区间与 Excel 导出
class ReportController extends GetxController {
  final api = ApiService();

  static const tabs = ['users', 'payments', 'withdrawals'];

  final activeTab = 'users'.obs;
  final startDate = DateTime.now().subtract(const Duration(days: 29)).obs;
  final endDate = DateTime.now().obs;
  final isLoading = false.obs;

  final stats = <String, dynamic>{}.obs; // 汇总数据
  final daily = <Map<String, dynamic>>[].obs; // 按日明细
  final distribution = <Map<String, dynamic>>[].obs; // 平台/状态分布

  static const _palette = ['#1677FF', '#52C41A', '#FA8C16', '#722ED1', '#13C2C2', '#EB2F96'];

  @override
  void onInit() {
    super.onInit();
    load();
  }

  String fmt(DateTime d) =>
      '${d.year}-${d.month.toString().padLeft(2, '0')}-${d.day.toString().padLeft(2, '0')}';

  String _yuan(dynamic cents) => ((cents as num? ?? 0) / 100).toStringAsFixed(2);

  /// 汇总卡片（label/value/icon/color 结构，与仪表盘一致）
  List<Map<String, dynamic>> get cards {
    final s = stats;
    return switch (activeTab.value) {
      'users' => [
          {'label': '总用户', 'value': '${s['total'] ?? 0}', 'icon': 'people', 'color': '#1677FF'},
          {'label': '区间新增', 'value': '${s['new_in_range'] ?? 0}', 'icon': 'person_add', 'color': '#52C41A'},
          {'label': '今日活跃(发帖)', 'value': '${s['active_today'] ?? 0}', 'icon': 'bolt', 'color': '#FA8C16'},
        ],
      'payments' => [
          {'label': '订单数', 'value': '${s['orders'] ?? 0}', 'icon': 'payments', 'color': '#FA8C16'},
          {'label': '成功金额(元)', 'value': _yuan(s['succeeded_amount_cents']), 'icon': 'savings', 'color': '#722ED1'},
        ],
      _ => [
          {'label': '提现笔数', 'value': '${s['count'] ?? 0}', 'icon': 'account_balance_wallet', 'color': '#13C2C2'},
          {'label': '提现金额(元)', 'value': _yuan(s['amount_cents']), 'icon': 'money_off', 'color': '#EB2F96'},
        ],
    };
  }

  /// 趋势序列：users 双线（新增/活跃），payments 金额线，withdrawals 笔数线
  List<List<FlSpot>> get trendSpots {
    if (daily.isEmpty) return [];
    return [0, 1].map((i) {
      final key = switch (activeTab.value) {
        'users' => i == 0 ? 'new' : 'active',
        'payments' => 'amount_cents',
        _ => 'count',
      };
      return daily.asMap().entries.map((e) {
        final v = (e.value[key] as num? ?? 0).toDouble();
        return FlSpot(e.key.toDouble(), v);
      }).toList();
    }).where((s) => s.isNotEmpty).toList();
  }

  List<PieChartSectionData> get pieSections {
    return distribution.asMap().entries.map((e) => PieChartSectionData(
          color: _color(_palette[e.key % _palette.length]),
          value: ((e.value['value'] as num?) ?? 0).toDouble(),
          title: '',
          radius: 30,
        )).toList();
  }

  List<Color> get distributionColors {
    return distribution.asMap().entries.map((e) => _color(_palette[e.key % _palette.length])).toList();
  }

  Color _color(String hex) => Color(int.parse('0xFF${hex.replaceFirst('#', '')}'));

  Future<void> load() async {
    isLoading.value = true;
    try {
      final resp = await api.get('/admin/report/${activeTab.value}',
          params: {'start': fmt(startDate.value), 'end': fmt(endDate.value)});
      final data = resp['data'] as Map<String, dynamic>;
      stats.value = Map<String, dynamic>.from(data['stats'] ?? {});
      daily.value = List<Map<String, dynamic>>.from(data['daily'] ?? []);
      final dist = <Map<String, dynamic>>[];
      for (final list in [data['status_distribution'], data['platform_distribution']]) {
        if (list != null) dist.addAll(List<Map<String, dynamic>>.from(list));
      }
      distribution.value = dist;
    } catch (e) {
      Get.snackbar('错误', '加载报表失败: $e');
    } finally {
      isLoading.value = false;
    }
  }

  Future<void> exportExcel() async {
    try {
      final resp = await api.dio.post(
        '/admin/report/export',
        data: {'type': activeTab.value, 'start': fmt(startDate.value), 'end': fmt(endDate.value)},
        options: Options(responseType: ResponseType.bytes),
      );
      await FileSaver.instance.saveFile(
        name: 'report_${activeTab.value}_${fmt(startDate.value)}_${fmt(endDate.value)}.xlsx',
        bytes: resp.data,
        ext: 'xlsx',
      );
      Get.snackbar('成功', '已导出 Excel');
    } catch (e) {
      Get.snackbar('错误', '导出失败: $e');
    }
  }
}
