// Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
import 'dart:math';
import 'package:flutter/material.dart';
import '../../../services/captcha_service.dart';

class RotateCaptcha extends StatefulWidget {
  final CaptchaData data;
  const RotateCaptcha({super.key, required this.data});
  @override
  State<RotateCaptcha> createState() => RotateCaptchaState();
}

class RotateCaptchaState extends State<RotateCaptcha> {
  double get answer => val;
  double val = 0;

  @override
  void didUpdateWidget(RotateCaptcha old) {
    super.didUpdateWidget(old);
    if (widget.data != old.data) setState(() => val = 0);
  }

  @override
  Widget build(BuildContext c) {
    final d = widget.data;
    return Column(mainAxisSize: MainAxisSize.min, children: [
      const Text('旋转图片使标记回到正上方', style: TextStyle(fontSize: 13)),
      const SizedBox(height: 10),
      AspectRatio(aspectRatio: 300 / 200, child: LayoutBuilder(builder: (_, box) {
        final cs = box.maxHeight * 0.65;
        return Stack(children: [
          Positioned.fill(child: Container(color: Colors.grey.shade200)),
          Center(child: ClipOval(child: SizedBox(width: cs, height: cs, child:
            Transform.rotate(angle: val * pi / 180, child:
              Image.memory(d.imageBytes, width: cs, height: cs, fit: BoxFit.contain),
            ),
          ))),
          const Positioned(top: 4, left: 0, right: 0, child: Icon(Icons.arrow_drop_up, size: 32, color: Colors.red)),
        ]);
      })),
      const SizedBox(height: 8),
      Row(children: [
        const Icon(Icons.rotate_left, size: 18, color: Colors.grey),
        Expanded(child: Slider(value: val, min: 0, max: 359, divisions: 359, onChanged: (v) => setState(() => val = v))),
        const Icon(Icons.rotate_right, size: 18, color: Colors.grey),
      ]),
      Center(child: Text('${val.round()}°', style: const TextStyle(fontSize: 12, color: Colors.grey))),
    ]);
  }
}
