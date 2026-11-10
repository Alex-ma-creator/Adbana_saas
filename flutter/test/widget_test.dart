import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:adbana_carta/menu_page.dart';
import 'package:adbana_carta/menu_widgets.dart';
import 'package:adbana_carta/menu_data.dart';

void main() {
  test('Solo los platos con modelo ofrecen la experiencia 3D', () {
    expect(
      dishes.singleWhere((dish) => dish.id == 'pizza').modelAsset,
      'assets/models/pizza.glb',
    );
    expect(dishes.where((dish) => dish.modelAsset != null).length, 1);
    expect(
      dishes
          .where((dish) => dish.id != 'pizza')
          .every((dish) => dish.modelAsset == null),
      isTrue,
    );
  });
  test('Los importes se calculan en céntimos', () {
    expect(money(3200 * 2 + 2600), 'S/ 90.00');
    expect(normalize('Albaháca'), 'albahaca');
  });

  testWidgets('Filtra categorías y encuentra ingredientes sin tildes', (
    tester,
  ) async {
    tester.view.devicePixelRatio = 1;
    tester.view.physicalSize = const Size(1280, 1100);
    addTearDown(tester.view.reset);
    await tester.pumpWidget(const MaterialApp(home: MenuPage()));
    await tester.pumpAndSettle();
    expect(find.byType(DishCard), findsNWidgets(4));
    await tester.tap(find.widgetWithText(ChoiceChip, 'Pastas'));
    await tester.pumpAndSettle();
    expect(find.byType(DishCard), findsOneWidget);
    expect(find.text('Pasta al pesto'), findsOneWidget);
    await tester.tap(find.widgetWithText(ChoiceChip, 'Todo'));
    await tester.enterText(find.byType(TextField), 'limon');
    await tester.pumpAndSettle();
    expect(find.text('Ensalada del huerto'), findsOneWidget);
    await tester.enterText(find.byType(TextField), 'inexistente');
    await tester.pumpAndSettle();
    expect(find.text('No encontramos ese plato'), findsOneWidget);
    await tester.tap(find.text('Ver toda la carta'));
    await tester.pumpAndSettle();
    expect(find.byType(DishCard), findsNWidgets(4));
  });

  testWidgets('Añade, cambia cantidades y elimina sin enviar pedidos', (
    tester,
  ) async {
    tester.view.devicePixelRatio = 1;
    tester.view.physicalSize = const Size(1280, 1100);
    addTearDown(tester.view.reset);
    await tester.pumpWidget(const MaterialApp(home: MenuPage()));
    await tester.pumpAndSettle();
    final add = find.byTooltip('Añadir Pizza de la casa');
    await tester.ensureVisible(add);
    await tester.pumpAndSettle();
    await tester.tap(add);
    await tester.pumpAndSettle();
    await tester.tap(find.text('Ver selección (1)'));
    await tester.pumpAndSettle();
    expect(find.textContaining('No se envían pedidos'), findsOneWidget);
    await tester.tap(find.byTooltip('Aumentar cantidad'));
    await tester.pumpAndSettle();
    expect(find.text('S/ 64.00'), findsWidgets);
    await tester.tap(find.byTooltip('Reducir cantidad'));
    await tester.pumpAndSettle();
    await tester.tap(find.byTooltip('Reducir cantidad'));
    await tester.pumpAndSettle();
    expect(find.textContaining('Tu selección está vacía'), findsOneWidget);
    expect(find.text('S/ 0.00'), findsOneWidget);
  });

  testWidgets('Detalle usable en móvil y límite de cantidad', (tester) async {
    tester.view.devicePixelRatio = 1;
    tester.view.physicalSize = const Size(390, 844);
    addTearDown(tester.view.reset);
    await tester.pumpWidget(const MaterialApp(home: MenuPage()));
    await tester.pumpAndSettle();
    expect(tester.takeException(), isNull);
    final name = find.text('Pizza de la casa');
    await tester.ensureVisible(name);
    await tester.pumpAndSettle();
    await tester.tap(name);
    await tester.pumpAndSettle();
    expect(find.text('Ingredientes'), findsOneWidget);
    final add = find.text('Añadir a mi selección · S/ 32.00');
    await tester.ensureVisible(add);
    await tester.pumpAndSettle();
    await tester.tap(add);
    await tester.pumpAndSettle();
    expect(find.text('Ver selección (1)'), findsOneWidget);
    expect(tester.takeException(), isNull);
  });
  testWidgets('Respeta las unidades que quedan antes del límite de selección', (
    tester,
  ) async {
    await tester.pumpWidget(
      MaterialApp(
        home: Scaffold(body: DishDetail(dish: dishes.first, available: 1)),
      ),
    );
    await tester.pumpAndSettle();
    final button = find.widgetWithIcon(IconButton, Icons.add_circle_outline);
    expect(tester.widget<IconButton>(button).onPressed, isNull);
    expect(find.text('Añadir a mi selección · S/ 32.00'), findsOneWidget);
    expect(tester.takeException(), isNull);
  });
}
