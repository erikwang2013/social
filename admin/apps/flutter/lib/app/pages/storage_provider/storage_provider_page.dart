/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */

import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'storage_provider_controller.dart';

class StorageProviderPage extends GetView<StorageProviderController> {
  const StorageProviderPage({super.key});

  @override
  Widget build(BuildContext context) {
    if (!Get.isRegistered<StorageProviderController>()) {
      Get.put(StorageProviderController(), permanent: false);
    }
    final ctrl = controller;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Wrap(
          crossAxisAlignment: WrapCrossAlignment.center,
          spacing: 8,
          children: [
            const Text('CDN 服务商', style: TextStyle(fontSize: 20, fontWeight: FontWeight.bold)),
            IconButton(icon: const Icon(Icons.refresh), tooltip: '刷新', onPressed: () => ctrl.loadProviders()),
            const SizedBox(width: 8),
            ElevatedButton.icon(
              icon: const Icon(Icons.add, size: 18),
              label: const Text('新增服务商'),
              onPressed: () => _showEditDialog(context, null),
            ),
          ],
        ),
        const SizedBox(height: 12),
        Expanded(
          child: Obx(() {
            if (ctrl.isLoading.value) return const Center(child: CircularProgressIndicator());
            if (ctrl.providers.isEmpty) return const Center(child: Text('暂无数据'));

            return SingleChildScrollView(
              child: SingleChildScrollView(
                scrollDirection: Axis.horizontal,
                child: DataTable(
                  columns: const [
                    DataColumn(label: Text('ID')),
                    DataColumn(label: Text('名称')),
                    DataColumn(label: Text('驱动')),
                    DataColumn(label: Text('Endpoint')),
                    DataColumn(label: Text('Bucket')),
                    DataColumn(label: Text('CDN URL')),
                    DataColumn(label: Text('状态')),
                    DataColumn(label: Text('操作')),
                  ],
                  rows: ctrl.providers.map((p) {
                    final active = (p['is_active'] ?? 0) == 1;
                    final id = (p['id'] as num).toInt();
                    return DataRow(
                      cells: [
                        DataCell(Text(p['id'].toString())),
                        DataCell(Text(p['name'] ?? '')),
                        DataCell(Text(p['driver'] ?? '')),
                        DataCell(Text(p['endpoint'] ?? '')),
                        DataCell(Text(p['bucket'] ?? '')),
                        DataCell(Text(p['cdn_url'] ?? '')),
                        DataCell(Chip(
                          label: Text(active ? '活动' : '停用'),
                          backgroundColor: active ? Colors.green.shade50 : Colors.grey.shade200,
                        )),
                        DataCell(Wrap(
                          spacing: 4,
                          children: [
                            TextButton(
                              onPressed: () => _showEditDialog(context, p),
                              child: const Text('编辑'),
                            ),
                            if (!active)
                              TextButton(
                                onPressed: () => ctrl.activate(id),
                                child: const Text('激活'),
                              ),
                            if (!active)
                              TextButton(
                                onPressed: () => _confirmDelete(context, ctrl, p),
                                child: const Text('删除'),
                              ),
                          ],
                        )),
                      ],
                    );
                  }).toList(),
                ),
              ),
            );
          }),
        ),
      ],
    );
  }

  void _showEditDialog(BuildContext context, dynamic p) {
    final isEdit = p != null;
    final nameCtrl = TextEditingController(text: p?['name'] ?? '');
    var driver = p?['driver'] ?? 'local';
    final endpointCtrl = TextEditingController(text: p?['endpoint'] ?? '');
    final regionCtrl = TextEditingController(text: p?['region'] ?? 'auto');
    final bucketCtrl = TextEditingController(text: p?['bucket'] ?? '');
    final cdnCtrl = TextEditingController(text: p?['cdn_url'] ?? '');
    final keyCtrl = TextEditingController(text: '');
    final secretCtrl = TextEditingController(text: '');

    showDialog(
      context: context,
      builder: (ctx) => StatefulBuilder(
        builder: (ctx, setState) => AlertDialog(
          title: Text(isEdit ? '编辑服务商 #${p['id']}' : '新增服务商'),
          content: SizedBox(
            width: 480,
            child: SingleChildScrollView(
              child: Column(
                mainAxisSize: MainAxisSize.min,
                children: [
                  TextField(controller: nameCtrl, decoration: const InputDecoration(labelText: '名称', isDense: true)),
                  const SizedBox(height: 8),
                  Row(children: [
                    ChoiceChip(label: const Text('本地'), selected: driver == 'local', onSelected: (_) => setState(() => driver = 'local')),
                    const SizedBox(width: 8),
                    ChoiceChip(label: const Text('S3 兼容'), selected: driver == 's3', onSelected: (_) => setState(() => driver = 's3')),
                  ]),
                  const SizedBox(height: 8),
                  TextField(controller: endpointCtrl, decoration: const InputDecoration(labelText: 'Endpoint（R2/OSS/COS/B2）', isDense: true)),
                  const SizedBox(height: 8),
                  TextField(controller: regionCtrl, decoration: const InputDecoration(labelText: 'Region（默认 auto）', isDense: true)),
                  const SizedBox(height: 8),
                  TextField(controller: bucketCtrl, decoration: const InputDecoration(labelText: 'Bucket', isDense: true)),
                  const SizedBox(height: 8),
                  TextField(controller: cdnCtrl, decoration: const InputDecoration(labelText: 'CDN URL（公开读域名）', isDense: true)),
                  const SizedBox(height: 8),
                  TextField(controller: keyCtrl, decoration: const InputDecoration(labelText: 'AccessKey（编辑时留空不修改）', isDense: true)),
                  const SizedBox(height: 8),
                  TextField(controller: secretCtrl, obscureText: true, decoration: const InputDecoration(labelText: 'SecretKey（编辑时留空不修改）', isDense: true)),
                ],
              ),
            ),
          ),
          actions: [
            TextButton(onPressed: () => Navigator.pop(ctx), child: const Text('取消')),
            ElevatedButton(
              onPressed: () {
                final name = nameCtrl.text.trim();
                if (name.isEmpty) {
                  Get.snackbar('提示', '名称必填');
                  return;
                }
                final data = <String, dynamic>{
                  'name': name,
                  'driver': driver,
                  'endpoint': endpointCtrl.text.trim(),
                  'region': regionCtrl.text.trim(),
                  'bucket': bucketCtrl.text.trim(),
                  'cdn_url': cdnCtrl.text.trim(),
                };
                if (keyCtrl.text.isNotEmpty) data['key'] = keyCtrl.text.trim();
                if (secretCtrl.text.isNotEmpty) data['secret'] = secretCtrl.text.trim();
                Navigator.pop(ctx);
                if (isEdit) {
                  controller.updateProvider((p['id'] as num).toInt(), data);
                } else {
                  controller.create(data);
                }
              },
              child: const Text('保存'),
            ),
          ],
        ),
      ),
    );
  }

  void _confirmDelete(BuildContext context, StorageProviderController ctrl, dynamic p) {
    showDialog(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('删除服务商'),
        content: Text('确认删除「${p['name']}」？活动服务商不可删除。'),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx), child: const Text('取消')),
          ElevatedButton(
            onPressed: () {
              Navigator.pop(ctx);
              ctrl.remove((p['id'] as num).toInt());
            },
            child: const Text('删除'),
          ),
        ],
      ),
    );
  }
}
