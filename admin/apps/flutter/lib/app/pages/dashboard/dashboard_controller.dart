// Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:fl_chart/fl_chart.dart';
import 'package:pdf/pdf.dart';
import 'package:pdf/widgets.dart' as pw;
import 'package:printing/printing.dart';
import '../../services/api_service.dart';

class DashboardController extends GetxController {
  final _api = ApiService();
  final isLoading = true.obs;

  final stats = <Map<String, dynamic>>[].obs;
  final trends = <String, dynamic>{}.obs;
  final recentLogs = <Map<String, dynamic>>[].obs;
  final platformStats = <Map<String, dynamic>>[].obs; // M6d 平台统计 6 卡片

  List<List<FlSpot>> get trendSpots {
    final allSeries = trends['series'] as List<dynamic>? ?? [];
    return allSeries.map((s) {
      final data = s['data'] as List<dynamic>? ?? [];
      return data.asMap().entries.map((e) => FlSpot(e.key.toDouble(), (e.value as num).toDouble())).toList();
    }).toList();
  }

  List<PieChartSectionData> get pieSections {
    return [
      PieChartSectionData(color: const Color(0xFF1677FF), value: 265, title: '', radius: 30),
      PieChartSectionData(color: const Color(0xFF52C41A), value: 35, title: '', radius: 30),
    ];
  }

  @override
  void onInit() {
    super.onInit();
    loadData();
  }

  Future<void> loadData() async {
    try {
      isLoading.value = true;
      final resp = await _api.get('/admin/dashboard');
      final data = resp['data'];
      stats.value = List<Map<String, dynamic>>.from(data['stats'] ?? []);
      trends.value = Map<String, dynamic>.from(data['trends'] ?? {});
      recentLogs.value = List<Map<String, dynamic>>.from(data['recent_logs'] ?? []);
      platformStats.value = List<Map<String, dynamic>>.from(data['platform_stats'] ?? []);
    } catch (e) {
      // 开发环境使用模拟数据
      stats.value = [
        {'label': '用户总数', 'value': '1,236', 'icon': 'people', 'color': '#1677FF', 'trend': 12.5},
        {'label': '今日新增', 'value': '28', 'icon': 'person_add', 'color': '#52C41A', 'trend': null},
        {'label': '活跃用户', 'value': '89', 'icon': 'bolt', 'color': '#FA8C16', 'trend': -3.2},
        {'label': '操作日志', 'value': '452', 'icon': 'description', 'color': '#722ED1', 'trend': 8.0},
      ];
      trends.value = {
        'dates': List.generate(30, (i) => 'Day $i'),
        'series': [
          {
            'name': '累计用户',
            'data': List.generate(30, (i) => 800 + i * 15 + (i > 20 ? 20 : 0)),
          },
        ],
      };
      platformStats.value = [
        {'label': '社交用户总数', 'value': '1,024', 'icon': 'people', 'color': '#1677FF'},
        {'label': '今日新增用户', 'value': '12', 'icon': 'person_add', 'color': '#52C41A'},
        {'label': '支付订单数', 'value': '356', 'icon': 'payments', 'color': '#FA8C16'},
        {'label': '今日充值(元)', 'value': '2,356.80', 'icon': 'savings', 'color': '#722ED1'},
        {'label': '提现笔数', 'value': '48', 'icon': 'account_balance', 'color': '#13C2C2'},
        {'label': '今日提现(元)', 'value': '1,200.00', 'icon': 'money_off', 'color': '#EB2F96'},
      ];
    } finally {
      isLoading.value = false;
    }
  }

  Future<void> exportPdf() async {
    final pdf = pw.Document();
    pdf.addPage(pw.MultiPage(
      pageFormat: PdfPageFormat.a4.landscape,
      build: (ctx) => [
        pw.Header(text: '仪表盘数据导出'),
        pw.Paragraph(text: 'Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz'),
        for (final s in stats)
          pw.Row(children: [
            pw.Text(s['label']),
            pw.Text(s['value'], style: pw.TextStyle(fontWeight: pw.FontWeight.bold)),
          ]),
      ],
    ));
    await Printing.sharePdf(bytes: await pdf.save(), filename: 'dashboard_export.pdf');
  }

  Future<void> exportExcel() async {
    Get.snackbar('导出', 'Excel 导出功能已触发');
  }
}
