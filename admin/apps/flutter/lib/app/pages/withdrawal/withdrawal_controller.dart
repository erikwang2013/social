/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */

import 'package:get/get.dart';
import '../../services/api_service.dart';

class WithdrawalController extends GetxController {
  final api = ApiService();

  final withdrawals = <dynamic>[].obs;
  final isLoading = false.obs;
  final total = 0.obs;
  final page = 1.obs;
  final limit = 15.obs;
  final statusFilter = Rx<String?>(null);

  @override
  void onInit() {
    super.onInit();
    loadWithdrawals();
  }

  Future<void> loadWithdrawals({bool reset = false}) async {
    if (reset) page.value = 1;
    isLoading.value = true;
    try {
      final params = <String, dynamic>{'page': page.value, 'page_size': limit.value};
      if (statusFilter.value != null) params['status'] = statusFilter.value;
      final resp = await api.get('/admin/withdrawal', params: params);
      withdrawals.value = resp['data']['list'] as List<dynamic>;
      total.value = resp['data']['total'] as int;
    } catch (e) {
      Get.snackbar('错误', '加载提现单失败: $e');
    } finally {
      isLoading.value = false;
    }
  }

  Future<void> filterByStatus(String? status) async {
    statusFilter.value = status;
    await loadWithdrawals(reset: true);
  }

  Future<void> nextPage() async {
    if (page.value * limit.value < total.value) {
      page.value++;
      await loadWithdrawals();
    }
  }

  Future<void> prevPage() async {
    if (page.value > 1) {
      page.value--;
      await loadWithdrawals();
    }
  }

  Future<bool> markStatus(int id, String status, String reason) async {
    try {
      await api.post('/admin/withdrawal/$id/status', data: {'status': status, 'reason': reason});
      await loadWithdrawals();
      Get.snackbar('成功', status == 'succeeded' ? '已标记成功' : '已标记失败');
      return true;
    } catch (e) {
      Get.snackbar('错误', '操作失败: $e');
      return false;
    }
  }
}
