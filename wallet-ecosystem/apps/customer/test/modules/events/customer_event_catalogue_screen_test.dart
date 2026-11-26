import 'dart:convert';
import 'dart:typed_data';

import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:go_router/go_router.dart';
import 'package:wallet_customer/modules/events/presentation/screens/customer_event_catalogue_screen.dart';
import 'package:wallet_customer/modules/events/presentation/widgets/event_category_style.dart';

class _CatalogueAdapter implements HttpClientAdapter {
  _CatalogueAdapter(this.events, {this.respond});

  final List<Map<String, dynamic>> events;
  final Map<String, dynamic> Function(RequestOptions)? respond;
  final List<String> paths = [];

  @override
  Future<ResponseBody> fetch(RequestOptions options,
      Stream<Uint8List>? requestStream, Future<void>? cancelFuture) async {
    paths.add(options.path);
    final payload = respond?.call(options) ?? {'data': events};
    return ResponseBody.fromString(
      jsonEncode(payload),
      200,
      headers: {
        Headers.contentTypeHeader: [Headers.jsonContentType],
      },
    );
  }

  @override
  void close({bool force = false}) {}
}

Map<String, dynamic> _event({
  required String id,
  required String title,
  required String category,
  String? imageUrl,
}) =>
    {
      'id': id,
      'title': title,
      'image_url': imageUrl,
      'event_category': category,
      'starts_at': '2027-04-18T14:30:00+02:00',
      'ends_at': '2027-04-18T18:00:00+02:00',
      'sales_start_at': '2027-01-01T00:00:00+02:00',
      'sales_end_at': '2027-04-18T18:00:00+02:00',
      'booking_status': 'open',
      'availability_status': 'available',
      'venue_name': 'Main Hall',
      'venue_city': 'Gaborone',
      'venue_address': 'Central District',
      'organiser_name': 'OleKard Community',
      'currency_code': 'BWP',
      'currency_precision': 2,
      'sponsors': <Object>[],
      'categories': [
        {
          'id': 'ticket-$id',
          'name': 'General',
          'capacity': 100,
          'available_quantity': 100,
          'price_minor': 0,
          'allocation_type': 'free',
          'max_per_person': 1,
        }
      ],
    };

void main() {
  test('supported event categories use stable distinct colours', () {
    const categories = [
      'community',
      'music',
      'sports',
      'club_game',
      'business',
      'arts',
      'family',
      'other',
    ];
    final colours = categories
        .map((category) =>
            EventCategoryStyle.resolve(category, Brightness.light).background)
        .toSet();
    expect(colours, hasLength(categories.length));
  });

  testWidgets('category and search results reuse compact event rows',
      (tester) async {
    final adapter = _CatalogueAdapter([
      _event(id: 'music-1', title: 'Community Music Day', category: 'music'),
      _event(
          id: 'club-1', title: 'Molepolole Club Game', category: 'club_game'),
      _event(
        id: 'community-1',
        title: 'A very long community event title that remains readable',
        category: 'community',
      ),
    ]);
    final dio = Dio(BaseOptions(baseUrl: 'https://demo.invalid'))
      ..httpClientAdapter = adapter;
    await tester.binding.setSurfaceSize(const Size(360, 1400));
    addTearDown(() => tester.binding.setSurfaceSize(null));

    final router = GoRouter(
      initialLocation: '/events/catalogue',
      routes: [
        GoRoute(
          path: '/events/catalogue',
          builder: (_, __) => const CustomerEventCatalogueScreen(),
        ),
        GoRoute(
          path: '/events/tickets',
          builder: (_, __) => const Scaffold(body: Text('My Tickets page')),
        ),
      ],
    );
    await tester.pumpWidget(ProviderScope(
      overrides: [customerEventCatalogueDioProvider.overrideWithValue(dio)],
      child: MaterialApp.router(
        routerConfig: router,
        builder: (context, child) => MediaQuery(
          data: MediaQuery.of(context)
              .copyWith(textScaler: const TextScaler.linear(1.25)),
          child: child!,
        ),
      ),
    ));
    await tester.pumpAndSettle();

    expect(find.text('Upcoming events'), findsOneWidget);
    expect(find.text('Club game'), findsOneWidget);
    expect(find.byType(Image),
        findsWidgets); // Missing art uses themed local artwork.

    await tester.tap(find.text('Club game'));
    await tester.pumpAndSettle();
    expect(find.text('Molepolole Club Game'), findsOneWidget);
    expect(find.text('Community Music Day'), findsNothing);

    await tester.drag(find.text('Club game').first, const Offset(-320, 0));
    await tester.pumpAndSettle();
    expect(find.text('Music'), findsOneWidget);
    await tester.tap(find.text('Music'));
    await tester.pumpAndSettle();
    expect(find.text('Community Music Day'), findsOneWidget);
    expect(
        find.textContaining('very long community event title'), findsNothing);

    await tester.drag(find.text('Music').first, const Offset(320, 0));
    await tester.pumpAndSettle();
    await tester.tap(find.text('All events'));
    await tester.pumpAndSettle();
    await tester.enterText(find.byType(TextField), 'not in catalogue');
    await tester.pumpAndSettle();
    expect(find.text('No events match your filters.'), findsOneWidget);
    router.dispose();
  });

  testWidgets(
      'More events toggles views without refetch and shows local date badge',
      (tester) async {
    final adapter = _CatalogueAdapter([
      for (var index = 1; index <= 5; index++)
        _event(
          id: 'event-$index',
          title: 'Demo Event $index',
          category: 'music',
        ),
    ]);
    final dio = Dio(BaseOptions(baseUrl: 'https://demo.invalid'))
      ..httpClientAdapter = adapter;
    await tester.binding.setSurfaceSize(const Size(360, 800));
    addTearDown(() => tester.binding.setSurfaceSize(null));
    final router = GoRouter(
      initialLocation: '/events/catalogue',
      routes: [
        GoRoute(
          path: '/events/catalogue',
          builder: (_, __) => const CustomerEventCatalogueScreen(),
        ),
        GoRoute(
          path: '/events/tickets',
          builder: (_, __) => const Scaffold(body: Text('My Tickets page')),
        ),
      ],
    );
    await tester.pumpWidget(ProviderScope(
      overrides: [customerEventCatalogueDioProvider.overrideWithValue(dio)],
      child: MaterialApp.router(routerConfig: router),
    ));
    await tester.pumpAndSettle();
    expect(find.text('More events'), findsOneWidget);
    expect(
        adapter.paths.where((path) => path == '/v1/events/customer/catalogue'),
        hasLength(1));

    await tester.tap(find.text('Cards'));
    await tester.pumpAndSettle();
    final verticalScroll = find.byWidgetPredicate((widget) =>
        widget is Scrollable && widget.axisDirection == AxisDirection.down);
    await tester.scrollUntilVisible(find.text('Apr'), 250,
        scrollable: verticalScroll);
    expect(find.text('Apr'), findsWidgets);
    expect(find.text('18'), findsWidgets);
    expect(adapter.paths, ['/v1/events/customer/catalogue']);

    await tester.tap(find.text('Demo Event 4').first);
    await tester.pumpAndSettle();
    expect(adapter.paths, contains('/v1/events/tickets'));
    await tester.tap(find.byTooltip('Back to events'));
    await tester.pumpAndSettle();
    expect(find.text('Apr'), findsWidgets);
    expect(
        adapter.paths.where((path) => path == '/v1/events/customer/catalogue'),
        hasLength(1));
    router.dispose();
  });

  testWidgets(
      'My Tickets is reachable and private invitations are not fetched into discovery',
      (tester) async {
    final adapter = _CatalogueAdapter([
      _event(
          id: 'public-1',
          title: 'Public Community Event',
          category: 'community'),
    ]);
    final dio = Dio(BaseOptions(baseUrl: 'https://demo.invalid'))
      ..httpClientAdapter = adapter;
    final router = GoRouter(
      initialLocation: '/events/catalogue',
      routes: [
        GoRoute(
          path: '/events/catalogue',
          builder: (_, __) => const CustomerEventCatalogueScreen(),
        ),
        GoRoute(
          path: '/events/tickets',
          builder: (_, __) => const Scaffold(body: Text('My Tickets page')),
        ),
      ],
    );
    await tester.pumpWidget(ProviderScope(
      overrides: [customerEventCatalogueDioProvider.overrideWithValue(dio)],
      child: MaterialApp.router(routerConfig: router),
    ));
    await tester.pumpAndSettle();
    expect(find.text('Private invitation event'), findsNothing);
    expect(adapter.paths, ['/v1/events/customer/catalogue']);

    await tester.tap(find.text('My Tickets'));
    await tester.pumpAndSettle();
    expect(find.text('My Tickets page'), findsOneWidget);
    router.dispose();
  });

  testWidgets('event detail shows gallery, event information and pinned CTA',
      (tester) async {
    final event = _event(
      id: 'music-detail',
      title: 'Community Music Night',
      category: 'music',
    )
      ..['description'] = 'An evening of live music for the community.'
      ..['lineup'] = 'Doors open\nLive performance'
      ..['sales_start_at'] = '2026-01-01T00:00:00+02:00'
      ..['gallery_image_urls'] = [
        'https://demo.invalid/gallery-2.jpg',
        'https://demo.invalid/gallery-3.jpg',
      ];
    final adapter = _CatalogueAdapter([event]);
    final dio = Dio(BaseOptions(baseUrl: 'https://demo.invalid'))
      ..httpClientAdapter = adapter;
    await tester.binding.setSurfaceSize(const Size(360, 1400));
    addTearDown(() => tester.binding.setSurfaceSize(null));

    final router = GoRouter(
      initialLocation: '/events/catalogue',
      routes: [
        GoRoute(
          path: '/events/catalogue',
          builder: (_, __) => const CustomerEventCatalogueScreen(),
        ),
        GoRoute(
          path: '/events/tickets',
          builder: (_, __) => const Scaffold(body: Text('My Tickets page')),
        ),
      ],
    );
    await tester.pumpWidget(ProviderScope(
      overrides: [customerEventCatalogueDioProvider.overrideWithValue(dio)],
      child: MaterialApp.router(
        routerConfig: router,
        builder: (context, child) => MediaQuery(
          data: MediaQuery.of(context)
              .copyWith(textScaler: const TextScaler.linear(1.25)),
          child: child!,
        ),
      ),
    ));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Community Music Night'));
    await tester.pumpAndSettle();

    expect(find.byType(PageView), findsOneWidget);
    expect(find.text('Register for free'), findsOneWidget);
    expect(find.text('About event'), findsOneWidget);
    expect(find.text('Lineup'), findsOneWidget);
    expect(find.text('Doors open'), findsOneWidget);
    expect(find.text('Live performance'), findsOneWidget);
    expect(find.text('Available tickets'), findsOneWidget);
    router.dispose();
  });

  testWidgets('sold out paid ticket cannot be selected or booked',
      (tester) async {
    final event = _event(
      id: 'sold-out-demo',
      title: 'Sold Out Showcase',
      category: 'music',
    )
      ..['availability_status'] = 'sold_out'
      ..['categories'] = [
        {
          'id': 'sold-ticket',
          'name': 'General admission',
          'available_quantity': 0,
          'price_minor': 10000,
          'allocation_type': 'paid',
          'max_per_person': 5,
        }
      ];
    final adapter = _CatalogueAdapter([event], respond: (request) {
      if (request.path.endsWith('/catalogue'))
        return {
          'data': [event]
        };
      if (request.path.endsWith('/tickets')) return {'data': <Object>[]};
      return {'data': <Object>[]};
    });
    final dio = Dio(BaseOptions(baseUrl: 'https://demo.invalid'))
      ..httpClientAdapter = adapter;
    await tester.binding.setSurfaceSize(const Size(360, 1200));
    addTearDown(() => tester.binding.setSurfaceSize(null));
    final router = GoRouter(
      initialLocation: '/events/catalogue',
      routes: [
        GoRoute(
          path: '/events/catalogue',
          builder: (_, __) => const CustomerEventCatalogueScreen(),
        ),
        GoRoute(
          path: '/events/tickets',
          builder: (_, __) => const Scaffold(body: Text('My Tickets page')),
        ),
      ],
    );
    await tester.pumpWidget(ProviderScope(
      overrides: [customerEventCatalogueDioProvider.overrideWithValue(dio)],
      child: MaterialApp.router(routerConfig: router),
    ));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Sold Out Showcase'));
    await tester.pumpAndSettle();
    expect(find.text('Sold out'), findsWidgets);
    final action = tester.widget<FilledButton>(
      find
          .ancestor(
            of: find.text('Sold out').last,
            matching: find.byType(FilledButton),
          )
          .first,
    );
    expect(action.onPressed, isNull);
    expect(find.byTooltip('Add one General admission'), findsNothing);
    router.dispose();
  });

  testWidgets('paid event opens payment sheet and reuses prepared checkout',
      (tester) async {
    final event = _event(
      id: 'paid-demo',
      title: 'Demo Paid Event',
      category: 'music',
    )
      ..['sales_start_at'] = '2026-01-01T00:00:00+02:00'
      ..['categories'] = [
        {
          'id': 'paid-ticket',
          'name': 'General admission',
          'capacity': 50,
          'available_quantity': 20,
          'price_minor': 15000,
          'allocation_type': 'paid',
          'max_per_person': 5,
        }
      ];
    final checkoutCalls = <String, int>{};
    final adapter = _CatalogueAdapter([event], respond: (request) {
      final path = request.path;
      checkoutCalls[path] = (checkoutCalls[path] ?? 0) + 1;
      if (path.endsWith('/catalogue'))
        return {
          'data': [event]
        };
      if (path.endsWith('/tickets')) return {'data': <Object>[]};
      if (path.endsWith('/inventory-holds')) {
        return {
          'data': {'id': 'hold-demo'}
        };
      }
      if (path.endsWith('/quotes')) {
        return {
          'data': {'quote_id': 'quote-demo'}
        };
      }
      if (path.endsWith('/orders')) {
        return {
          'data': {
            'order_id': 'order-demo',
            'line_snapshot': [
              {
                'category_id': 'paid-ticket',
                'category_name': 'General admission',
                'quantity': 1,
                'unit_price_minor': 15000,
                'subtotal_minor': 15000,
              }
            ],
            'pricing': {
              'currency_code': 'BWP',
              'currency_precision': 2,
              'subtotal_minor': 15000,
              'fees_minor': 0,
              'taxes_minor': 0,
              'total_minor': 15000,
            },
            'total_minor': 15000,
          }
        };
      }
      if (path.endsWith('/payment-intent')) {
        return {
          'data': {
            'payment_intent_id': 'intent-demo',
            'available_payment_options': [
              {
                'provider_code': 'mock_bank',
                'provider_account_id': 'bank-demo',
                'payment_method': 'bank',
                'display_label': 'Demo Bank',
                'is_default': true,
              }
            ],
          }
        };
      }
      return {'data': <Object>[]};
    });
    final dio = Dio(BaseOptions(baseUrl: 'https://demo.invalid'))
      ..httpClientAdapter = adapter;
    await tester.binding.setSurfaceSize(const Size(360, 1100));
    addTearDown(() => tester.binding.setSurfaceSize(null));
    final router = GoRouter(
      initialLocation: '/events/catalogue',
      routes: [
        GoRoute(
          path: '/events/catalogue',
          builder: (_, __) => const CustomerEventCatalogueScreen(),
        ),
        GoRoute(
          path: '/events/tickets',
          builder: (_, __) => const Scaffold(body: Text('My Tickets page')),
        ),
      ],
    );
    await tester.pumpWidget(ProviderScope(
      overrides: [customerEventCatalogueDioProvider.overrideWithValue(dio)],
      child: MaterialApp.router(routerConfig: router),
    ));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Demo Paid Event'));
    await tester.pumpAndSettle();
    await tester.tap(find.byTooltip('Add one General admission'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Book tickets'));
    await tester.pumpAndSettle();
    expect(find.text('Payment'), findsOneWidget);
    expect(find.text('Pay now'), findsOneWidget);
    expect(find.text('BWP 150.00'), findsWidgets);
    await tester.tap(find.text('Cancel'));
    await tester.pumpAndSettle();

    await tester.tap(find.text('Book tickets'));
    await tester.pumpAndSettle();
    expect(find.text('Payment'), findsOneWidget);
    expect(checkoutCalls.values.fold<int>(0, (sum, value) => sum + value), 6);
    await tester.tap(find.text('Cancel'));
    await tester.pumpAndSettle();
    router.dispose();
  });
}
