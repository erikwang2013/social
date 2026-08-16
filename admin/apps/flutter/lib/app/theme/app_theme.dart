// Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
import 'package:flutter/material.dart';

const bg = Color(0xFFEFF1F7);
const cardBg = Color(0xFFFFFFFF);
const accent1 = Color(0xFF6366F1);
const accent2 = Color(0xFF8B5CF6);
const sidebarTop = Color(0xFF1A1547);
const sidebarBot = Color(0xFF2D2468);

final glassShadow = [
  BoxShadow(color: accent1.withValues(alpha: 0.06), blurRadius: 16, offset: const Offset(0, 4)),
  BoxShadow(color: Colors.black.withValues(alpha: 0.02), blurRadius: 4, offset: const Offset(0, 1)),
];

class AppTheme {
  static final ThemeData light = ThemeData(
    useMaterial3: true,
    colorScheme: ColorScheme.fromSeed(seedColor: accent1, primary: accent1, surface: cardBg, surfaceContainerLowest: bg, brightness: Brightness.light),
    scaffoldBackgroundColor: bg,
    cardTheme: CardThemeData(elevation: 0, color: cardBg, margin: EdgeInsets.zero, shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16))),
    dataTableTheme: DataTableThemeData(dataRowMinHeight: 48, dataRowMaxHeight: 48, headingRowHeight: 40, dividerThickness: 0, headingTextStyle: const TextStyle(fontWeight: FontWeight.w600, fontSize: 12, color: Color(0xFF9CA3AF)), dataTextStyle: const TextStyle(fontSize: 13)),
    inputDecorationTheme: const InputDecorationTheme(border: OutlineInputBorder(borderRadius: BorderRadius.all(Radius.circular(12))), contentPadding: EdgeInsets.symmetric(horizontal: 14, vertical: 12), filled: true, fillColor: Color(0xFFF4F5FA)),
    dividerTheme: const DividerThemeData(space: 0, thickness: 1, color: Color(0xFFF0F0F7)),
    filledButtonTheme: FilledButtonThemeData(style: FilledButton.styleFrom(backgroundColor: accent1, foregroundColor: Colors.white, shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)), minimumSize: const Size(0, 44), elevation: 0)),
    elevatedButtonTheme: ElevatedButtonThemeData(style: ElevatedButton.styleFrom(shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)), minimumSize: const Size(0, 44), elevation: 0)),
    chipTheme: ChipThemeData(shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(8)), side: BorderSide.none, backgroundColor: Colors.white, selectedColor: const Color(0xFFEEF0FF)),
  );

  static const sidebarGradient = LinearGradient(colors: [sidebarTop, sidebarBot], begin: Alignment.topCenter, end: Alignment.bottomCenter);
  static BoxDecoration cardDecoration() => BoxDecoration(color: cardBg, borderRadius: BorderRadius.circular(16), boxShadow: glassShadow);
  static BoxDecoration statCard(Color accent) => BoxDecoration(color: cardBg, borderRadius: BorderRadius.circular(16), boxShadow: glassShadow, border: Border(top: BorderSide(color: accent, width: 3)));

  static final ThemeData dark = ThemeData(useMaterial3: true, colorScheme: ColorScheme.fromSeed(seedColor: accent1, primary: accent1, brightness: Brightness.dark), cardTheme: CardThemeData(elevation: 0, color: const Color(0xFF1C1C2E), margin: EdgeInsets.zero, shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16))));
}
