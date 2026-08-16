// Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:responsive_framework/responsive_framework.dart';
import '../services/auth_service.dart';
import '../i18n/translations.dart';
import '../i18n/locale_service.dart';
import '../pages/user/user_list_page.dart';
import '../pages/role/role_list_page.dart';
import '../pages/config/config_page.dart';
import '../pages/log/log_page.dart';
import '../pages/dashboard/dashboard_page.dart';
import '../pages/profile/profile_page.dart';
import '../theme/app_theme.dart';

class AdminLayout extends StatefulWidget {
  final Widget child;
  final int initialIndex;
  const AdminLayout({super.key, required this.child, this.initialIndex = 0});

  @override
  State<AdminLayout> createState() => _AdminLayoutState();
}

class _AdminLayoutState extends State<AdminLayout> {
  late int _selectedIndex = widget.initialIndex;
  late Widget _currentChild;
  bool _sidebarCollapsed = false;
  bool _showCollapsedContent = false;
  String? _previousBreakpoint;
  static const double sidebarWidth = 240;
  static const double sidebarCollapsedWidth = 64;
  static const double headerHeight = 56;

  static const _pages = <Widget>[
    DashboardPage(),
    UserListPage(),
    RoleListPage(),
    ConfigPage(),
    LogPage(),
  ];

  ResponsiveBreakpointsData get _bp => ResponsiveBreakpoints.of(context);
  bool get _isPhone => _bp.smallerThan(TABLET);
  bool get _isTablet => _bp.equals(TABLET);

  @override
  void initState() {
    super.initState();
    _currentChild = _pages[_selectedIndex];
    _checkAuth();
  }

  void _checkAuth() async {
    final loggedIn = await AuthService.isLoggedIn();
    if (!loggedIn && mounted) {
      Get.offAllNamed('/login');
    }
  }

  @override
  void didChangeDependencies() {
    super.didChangeDependencies();
    final current = _bp.breakpoint.name;
    if (_previousBreakpoint != null && _previousBreakpoint != current) {
      _sidebarCollapsed = _isTablet;
    }
    _previousBreakpoint = current;
  }

  void _toggleSidebar() {
    setState(() {
      _sidebarCollapsed = !_sidebarCollapsed;
      // collapsing: switch to collapsed content immediately
      if (_sidebarCollapsed) _showCollapsedContent = true;
    });
  }

  void _onNavChanged(int index) {
    setState(() {
      _selectedIndex = index;
      _currentChild = _pages[index.clamp(0, _pages.length - 1)];
    });
  }

  @override
  Widget build(BuildContext context) {
    if (_isPhone) return _buildPhoneLayout();
    return _buildDesktopLayout();
  }

  // ─── PHONE layout: AppBar + Drawer ────────────────────────────────

  Widget _buildPhoneLayout() {
    return Scaffold(
      appBar: AppBar(
        title: const Text('管理后台'),
        actions: [_buildUserMenu()],
      ),
      drawer: Drawer(
        child: NavigationDrawer(
          selectedIndex: _selectedIndex,
          onDestinationSelected: _onNavChanged,
          children: [
            Container(
              height: headerHeight,
              padding: const EdgeInsets.symmetric(horizontal: 16),
              alignment: Alignment.centerLeft,
              child: const Row(
                children: [
                  Icon(Icons.admin_panel_settings, size: 24),
                  SizedBox(width: 8),
                  Text('管理后台',
                      style: TextStyle(fontSize: 18, fontWeight: FontWeight.bold)),
                ],
              ),
            ),
            const Divider(),
            ..._buildNavItems(),
          ],
        ),
      ),
      body: Column(children: [
        Expanded(child: Container(
          color: Theme.of(context).colorScheme.surfaceContainerLowest,
          padding: const EdgeInsets.fromLTRB(16, 16, 16, 0),
          child: _currentChild,
        )),
        _buildCopyright(),
      ]),
    );
  }

  // ─── DESKTOP / TABLET layout: sidebar + header + content ───────────

  Widget _buildDesktopLayout() {
    return Scaffold(
      body: Row(
        children: [
          _buildSidebar(),
          Expanded(
            child: Column(
              children: [
                _buildHeader(),
                Expanded(
                  child: Container(
                    color: Theme.of(context).colorScheme.surfaceContainerLowest,
                    padding: const EdgeInsets.fromLTRB(16, 16, 16, 0),
                    child: _currentChild,
                  ),
                ),
                _buildCopyright(),
              ],
            ),
          ),
        ],
      ),
    );
  }

  static const _accent = Color(0xFF4F6EF7);

  Widget _buildSidebar() {
    final width = _sidebarCollapsed ? sidebarCollapsedWidth : sidebarWidth;
    final showCollapsed = _showCollapsedContent;
    final items = [
      (showCollapsed ? null : t('nav_dashboard'), Icons.dashboard),
      (showCollapsed ? null : t('nav_users'), Icons.people),
      (showCollapsed ? null : t('nav_roles'), Icons.security),
      (showCollapsed ? null : t('nav_config'), Icons.settings),
      (showCollapsed ? null : t('nav_logs'), Icons.description),
    ];

    return AnimatedContainer(
      duration: const Duration(milliseconds: 200),
      width: width,
      clipBehavior: Clip.hardEdge,
      onEnd: () {
        if (!_sidebarCollapsed && _showCollapsedContent) {
          setState(() => _showCollapsedContent = false);
        }
      },
      decoration: const BoxDecoration(
        gradient: AppTheme.sidebarGradient,
        border: Border(right: BorderSide(color: Color(0x15FFFFFF))),
      ),
      child: Column(
        children: [
          Container(
            height: headerHeight,
            padding: EdgeInsets.symmetric(horizontal: showCollapsed ? 16.0 : 20.0),
            alignment: Alignment.centerLeft,
            child: showCollapsed
                ? const Icon(Icons.admin_panel_settings, size: 26, color: _accent)
                : Row(children: [
                    const Icon(Icons.admin_panel_settings, size: 24, color: _accent),
                    const SizedBox(width: 10),
                    Flexible(child: Text('开放管理后台', overflow: TextOverflow.ellipsis, style: const TextStyle(fontSize: 16, fontWeight: FontWeight.w700, color: Colors.white))),
                  ]),
          ),
          const Divider(height: 1, color: Color(0x20FFFFFF)),
          Expanded(
            child: ListView(
              padding: const EdgeInsets.symmetric(vertical: 8, horizontal: 12),
              children: List.generate(items.length, (i) {
                final (label, icon) = items[i];
                final selected = _selectedIndex == i;
                return Padding(
                  padding: const EdgeInsets.only(bottom: 2),
                  child: Material(
                    color: selected ? const Color(0x25A78BFA) : Colors.transparent,
                    borderRadius: BorderRadius.circular(10),
                    child: InkWell(
                      borderRadius: BorderRadius.circular(10),
                      onTap: () => _onNavChanged(i),
                      child: Container(
                        height: 42,
                        padding: showCollapsed
                            ? EdgeInsets.zero
                            : const EdgeInsets.only(left: 14),
                        decoration: BoxDecoration(
                          border: selected ? const Border(left: BorderSide(color: _accent, width: 3)) : null,
                          borderRadius: BorderRadius.circular(10),
                        ),
                        child: Row(
                          mainAxisAlignment: showCollapsed ? MainAxisAlignment.center : MainAxisAlignment.start,
                          children: [
                          Icon(icon, size: 20, color: selected ? _accent : const Color(0x99FFFFFF)),
                          if (!showCollapsed) ...[
                            const SizedBox(width: 12),
                            Flexible(child: Text(label ?? '', overflow: TextOverflow.ellipsis, style: TextStyle(fontSize: 14, fontWeight: selected ? FontWeight.w600 : FontWeight.normal, color: selected ? Colors.white : const Color(0x99FFFFFF)))),
                          ],
                        ]),
                      ),
                    ),
                  ),
                );
              }),
            ),
          ),
        ],
      ),
    );
  }

  static const _cr = 'Q29weXJpZ2h0IChjKSAyMDI2IGVyaWsgPGVyaWtAZXJpay54eXo+IOKAlCBodHRwczovL2VyaWsueHl6';

  Widget _buildCopyright() => Container(
    width: double.infinity, padding: const EdgeInsets.symmetric(vertical: 8),
    color: Theme.of(context).colorScheme.surfaceContainerLowest,
    child: Text(utf8.decode(base64Decode(_cr)), textAlign: TextAlign.center, style: TextStyle(fontSize: 11, color: Colors.grey.shade400)),
  );

  // Phone drawer still uses NavigationDrawer
  List<NavigationDrawerDestination> _buildNavItems() {
    return [
      NavigationDrawerDestination(
        icon: const Icon(Icons.dashboard, size: 20),
        label: Text(t('nav_dashboard')),
        selectedIcon: const Icon(Icons.dashboard, size: 20),
      ),
      NavigationDrawerDestination(
        icon: const Icon(Icons.people, size: 20),
        label: Text(t('nav_users')),
        selectedIcon: const Icon(Icons.people, size: 20),
      ),
      NavigationDrawerDestination(
        icon: const Icon(Icons.security, size: 20),
        label: Text(t('nav_roles')),
        selectedIcon: const Icon(Icons.security, size: 20),
      ),
      NavigationDrawerDestination(
        icon: const Icon(Icons.settings, size: 20),
        label: Text(t('nav_config')),
        selectedIcon: const Icon(Icons.settings, size: 20),
      ),
      NavigationDrawerDestination(
        icon: const Icon(Icons.description, size: 20),
        label: Text(t('nav_logs')),
        selectedIcon: const Icon(Icons.description, size: 20),
      ),
    ];
  }

  Widget _buildHeader() {
    return Container(
      height: headerHeight,
      padding: const EdgeInsets.symmetric(horizontal: 16),
      decoration: BoxDecoration(
        color: Theme.of(context).colorScheme.surface,
        border: Border(
          bottom: BorderSide(color: Theme.of(context).dividerColor),
        ),
      ),
      child: Row(
        children: [
          IconButton(
            icon: Icon(_sidebarCollapsed ? Icons.menu_open : Icons.menu),
            tooltip: _sidebarCollapsed ? '展开菜单' : '收起菜单',
            onPressed: _toggleSidebar,
          ),
          const Spacer(),
          IconButton(
            icon: const Icon(Icons.translate, size: 20),
            tooltip: '语言 / Language',
            onPressed: () => Get.find<LocaleService>().toggle(),
          ),
          _buildUserMenu(),
        ],
      ),
    );
  }

  Widget _buildUserMenu() {
    return PopupMenuButton<String>(
      offset: const Offset(0, headerHeight),
      child: const Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          CircleAvatar(radius: 14, child: Icon(Icons.person, size: 16)),
          SizedBox(width: 8),
          Text('管理员', style: TextStyle(fontSize: 14)),
          Icon(Icons.arrow_drop_down, size: 20),
        ],
      ),
      onSelected: (value) {
        if (value == 'profile') {
          Navigator.of(context).push(MaterialPageRoute(builder: (_) => const ProfilePage()));
        } else if (value == 'logout') {
          showDialog(
            context: context,
            builder: (ctx) => AlertDialog(
              title: const Text('确认退出'),
              content: const Text('确定要退出登录吗？'),
              actions: [
                TextButton(onPressed: () => Navigator.pop(ctx), child: const Text('取消')),
                TextButton(
                  onPressed: () async {
                    Navigator.pop(ctx);
                    await AuthService.clearToken();
                    Get.offAllNamed('/login');
                  },
                  child: const Text('确定退出', style: TextStyle(color: Colors.red)),
                ),
              ],
            ),
          );
        }
      },
      itemBuilder: (_) => [
        const PopupMenuItem(value: 'profile', child: Text('个人中心')),
        const PopupMenuItem(value: 'logout', child: Text('退出登录')),
      ],
    );
  }
}
