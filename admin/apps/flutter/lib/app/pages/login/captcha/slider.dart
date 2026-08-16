// Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
import 'package:flutter/material.dart';
import '../../../services/captcha_service.dart';

const _sw = 300.0, _sh = 200.0;

class SliderCaptcha extends StatefulWidget {
  final CaptchaData data;
  const SliderCaptcha({super.key, required this.data});
  @override
  State<SliderCaptcha> createState() => SliderCaptchaState();
}

class SliderCaptchaState extends State<SliderCaptcha> {
  double get answer => _val.value;
  final _val = ValueNotifier<double>(0);

  @override
  void didUpdateWidget(SliderCaptcha old) {
    super.didUpdateWidget(old);
    if (widget.data != old.data) _val.value = 0;
  }

  @override
  void dispose() { _val.dispose(); super.dispose(); }

  @override
  Widget build(BuildContext c) {
    final d = widget.data;
    final p = d.puzzleBytes;
    return Column(mainAxisSize: MainAxisSize.min, children: [
      const Text('拖动拼图片对齐缺口', style: TextStyle(fontSize: 13)),
      const SizedBox(height: 8),
      AspectRatio(aspectRatio: _sw / _sh, child: LayoutBuilder(builder: (_, box) {
        final w = box.maxWidth, h = box.maxHeight;
        final pw = w * d.puzzleW / _sw;
        final ph = h * d.puzzleH / _sh;
        return ClipRRect(borderRadius: BorderRadius.circular(8), child: Stack(children: [
          Image.memory(d.imageBytes, width: w, height: h, fit: BoxFit.fill),
          if (p != null)
            ValueListenableBuilder<double>(
              valueListenable: _val,
              builder: (_, val, child) => Positioned(
                left: (val / _sw * w).clamp(0, w - pw),
                top: d.sliderY / _sh * h,
                child: Image.memory(p, width: pw, height: ph, fit: BoxFit.fill),
              ),
            ),
        ]));
      })),
      const SizedBox(height: 10),
      LayoutBuilder(builder: (_, tb) {
        final trackW = tb.maxWidth;
        const thumbSize = 40.0;
        final track = trackW - thumbSize;
        // GestureDetector 保持在独立的 StatefulWidget 中，不被父级 rebuild 销毁
        return _Track(
          track: track,
          thumbSize: thumbSize,
          val: _val,
          onDrag: (dx) => _val.value = (dx / track * _sw).clamp(0.0, _sw),
        );
      }),
    ]);
  }
}

class _Track extends StatefulWidget {
  final double track;
  final double thumbSize;
  final ValueNotifier<double> val;
  final ValueChanged<double> onDrag;

  const _Track({
    required this.track,
    required this.thumbSize,
    required this.val,
    required this.onDrag,
  });

  @override
  State<_Track> createState() => _TrackState();
}

class _TrackState extends State<_Track> {
  late double _track = widget.track;
  late double _thumbSize = widget.thumbSize;

  @override
  void didUpdateWidget(_Track old) {
    super.didUpdateWidget(old);
    if (widget.track != old.track) {
      _track = widget.track;
      _thumbSize = widget.thumbSize;
    }
  }

  void _updatePos(DragStartDetails d) {
    final box = context.findRenderObject() as RenderBox?;
    if (box == null) return;
    final dx = (box.globalToLocal(d.globalPosition).dx - _thumbSize / 2).clamp(0.0, _track);
    widget.onDrag(dx);
  }

  void _updateDrag(DragUpdateDetails d) {
    final box = context.findRenderObject() as RenderBox?;
    if (box == null) return;
    final dx = (box.globalToLocal(d.globalPosition).dx - _thumbSize / 2).clamp(0.0, _track);
    widget.onDrag(dx);
  }

  @override
  Widget build(BuildContext c) => GestureDetector(
    onHorizontalDragStart: _updatePos,
    onHorizontalDragUpdate: _updateDrag,
    child: Container(
      height: 40,
      decoration: BoxDecoration(color: Colors.grey.shade200, borderRadius: BorderRadius.circular(20)),
      child: Stack(children: [
        ValueListenableBuilder<double>(
          valueListenable: widget.val,
          builder: (_, val, child) {
            final frac = _track > 0 ? val / _sw : 0.0;
            return Positioned(
              left: frac * _track,
              top: 2,
              child: Container(
                width: 36, height: 36,
                decoration: const BoxDecoration(color: Color(0xFF1677FF), shape: BoxShape.circle),
                child: const Icon(Icons.arrow_forward, color: Colors.white, size: 20),
              ),
            );
          },
        ),
      ]),
    ),
  );
}
