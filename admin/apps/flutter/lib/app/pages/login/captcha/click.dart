// Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
import 'package:flutter/material.dart';
import '../../../services/captcha_service.dart';

const _sw = 300.0, _sh = 200.0;

class ClickCaptcha extends StatefulWidget {
  final CaptchaData data;
  const ClickCaptcha({super.key, required this.data});
  @override
  State<ClickCaptcha> createState() => ClickCaptchaState();
}

class ClickCaptchaState extends State<ClickCaptcha> {
  List<Offset> get answer => clicks;
  List<Offset> clicks = [];

  @override
  void didUpdateWidget(ClickCaptcha old) {
    super.didUpdateWidget(old);
    if (widget.data != old.data) setState(() => clicks.clear());
  }

  @override
  Widget build(BuildContext c) {
    final d = widget.data;
    final ts = d.targets;
    return Column(mainAxisSize: MainAxisSize.min, children: [
      Text('按顺序点击: ${ts.map((t) => '"${t["text"]}"').join(' → ')}', style: const TextStyle(fontSize: 13)),
      const SizedBox(height: 8),
      AspectRatio(aspectRatio: _sw / _sh, child: LayoutBuilder(builder: (_, box) =>
        ClipRRect(borderRadius: BorderRadius.circular(8), child: GestureDetector(
          onTapUp: (e) {
            final tap = Offset(e.localPosition.dx / box.maxWidth * _sw, e.localPosition.dy / box.maxHeight * _sh);
            final i = clicks.indexWhere((c) => (c - tap).distance < 18);
            setState(() => i >= 0 ? clicks.removeAt(i) : clicks.length < ts.length ? clicks.add(tap) : null);
          },
          child: Stack(children: [
            Image.memory(d.imageBytes, width: box.maxWidth, height: box.maxHeight, fit: BoxFit.fill),
            for (int i = 0; i < clicks.length; i++)
              Positioned(left: clicks[i].dx / _sw * box.maxWidth - 14, top: clicks[i].dy / _sh * box.maxHeight - 14,
                child: Container(width: 28, height: 28, decoration: const BoxDecoration(shape: BoxShape.circle, color: Color(0xFF1677FF)),
                  child: Center(child: Text('${i + 1}', style: const TextStyle(color: Colors.white, fontSize: 14, fontWeight: FontWeight.bold))))),
          ]),
        )),
      )),
      const SizedBox(height: 4),
      Text('${clicks.length}/${ts.length} 已标记', style: const TextStyle(fontSize: 12, color: Colors.grey)),
    ]);
  }
}
