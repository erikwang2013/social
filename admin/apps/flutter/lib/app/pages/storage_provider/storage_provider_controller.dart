/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */

import 'package:get/get.dart';
import '../../services/api_service.dart';

class StorageProviderController extends GetxController {
  final api = ApiService();

  final providers = <dynamic>[].obs;
  final isLoading = false.obs;

  @override
  void onInit() {
    super.onInit();
    loadProviders();
  }

  Future<void> loadProviders() async {
    isLoading.value = true;
    try {
      final resp = await api.get('/admin/storage/providers');
      providers.value = (resp['data'] as List<dynamic>).cast<dynamic>();
    } catch (e) {
      Get.snackbar('错误', '加载服务商失败: $e');
    } finally {
      isLoading.value = false;
    }
  }

  Future<bool> create(Map<String, dynamic> data) async {
    try {
      await api.post('/admin/storage/providers', data: data);
      await loadProviders();
      Get.snackbar('成功', '已创建服务商');
      return true;
    } catch (e) {
      Get.snackbar('错误', '创建失败: $e');
      return false;
    }
  }

  Future<bool> updateProvider(int id, Map<String, dynamic> data) async {
    try {
      await api.put('/admin/storage/providers/$id', data: data);
      await loadProviders();
      Get.snackbar('成功', '已更新服务商');
      return true;
    } catch (e) {
      Get.snackbar('错误', '更新失败: $e');
      return false;
    }
  }

  Future<bool> remove(int id) async {
    try {
      await api.delete('/admin/storage/providers/$id');
      await loadProviders();
      Get.snackbar('成功', '已删除服务商');
      return true;
    } catch (e) {
      Get.snackbar('错误', '删除失败: $e');
      return false;
    }
  }

  Future<bool> activate(int id) async {
    try {
      await api.post('/admin/storage/providers/$id/activate');
      await loadProviders();
      Get.snackbar('成功', '已切换为活动服务商');
      return true;
    } catch (e) {
      Get.snackbar('错误', '激活失败: $e');
      return false;
    }
  }
}
