/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */

import 'package:flutter/material.dart';
import 'package:get/get.dart';
import '../../services/api_service.dart';
import '../../services/encryption_service.dart';

class RoleController extends GetxController {
  final api = ApiService();
  final roles = <dynamic>[].obs;
  final permissions = <dynamic>[].obs;
  final isLoading = false.obs;
  final total = 0.obs;
  final page = 1.obs;
  final limit = 15.obs;
  final keyword = ''.obs;
  final statusFilter = Rx<int?>(null);

  @override
  void onInit() {
    super.onInit();
    loadRoles();
    loadPermissions();
  }

  Future<void> loadRoles({bool reset = false}) async {
    if (reset) page.value = 1;
    isLoading.value = true;
    try {
      final params = <String, dynamic>{'page': page.value, 'limit': limit.value};
      if (keyword.value.isNotEmpty) params['keyword'] = keyword.value;
      if (statusFilter.value != null) params['status'] = statusFilter.value;
      final resp = await api.get('/admin/role', params: params);
      roles.value = resp['data']['list'] as List<dynamic>;
      total.value = resp['data']['total'] as int;
    } catch (e) {
      Get.snackbar('错误', '加载角色列表失败: $e');
    } finally {
      isLoading.value = false;
    }
  }

  Future<void> search(String kw) async {
    keyword.value = kw;
    await loadRoles(reset: true);
  }

  Future<void> filterByStatus(int? status) async {
    statusFilter.value = status;
    await loadRoles(reset: true);
  }

  Future<void> loadPermissions() async {
    try {
      final resp = await api.get('/admin/permission');
      permissions.value = resp['data'] as List<dynamic>? ?? [];
    } catch (_) {}
  }

  Future<bool> createRole(String name, String slug, String desc, List<String> permIds) async {
    try {
      await api.post('/admin/role', data: {
        'name': name, 'slug': slug, 'description': desc, 'permission_ids': permIds,
      });
      await loadRoles();
      Get.snackbar('成功', '角色创建成功');
      return true;
    } catch (e) {
      Get.snackbar('错误', '创建失败: $e');
      return false;
    }
  }

  Future<bool> updateRole(String id, {String? name, String? desc, List<String>? permIds}) async {
    try {
      final data = <String, dynamic>{};
      if (name != null) data['name'] = name;
      if (desc != null) data['description'] = desc;
      if (permIds != null) data['permission_ids'] = permIds;
      await api.put('/admin/role/$id', data: data);
      await loadRoles();
      Get.snackbar('成功', '角色更新成功');
      return true;
    } catch (e) {
      Get.snackbar('错误', '更新失败: $e');
      return false;
    }
  }

  Future<bool> deleteRole(String id, String password) async {
    try {
      await api.delete('/admin/role/$id', data: {'password': EncryptionService.encrypt(password)});
      await loadRoles();
      Get.snackbar('成功', '角色删除成功');
      return true;
    } catch (e) {
      Get.snackbar('错误', '删除失败: $e');
      return false;
    }
  }
}
