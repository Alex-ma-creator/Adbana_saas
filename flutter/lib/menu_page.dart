import 'package:flutter/material.dart';

import 'palette.dart';
import 'menu_data.dart';
import 'menu_widgets.dart';

class MenuPage extends StatefulWidget {
  const MenuPage({super.key});
  @override
  State<MenuPage> createState() => _MenuPageState();
}

class _MenuPageState extends State<MenuPage> {
  String category = 'Todo', query = '';
  final search = TextEditingController();
  final Map<String, int> basket = {};
  final Set<String> favorites = {};
  int get units => basket.values.fold(0, (sum, count) => sum + count);
  int get total =>
      dishes.fold(0, (sum, dish) => sum + dish.cents * (basket[dish.id] ?? 0));
  @override
  void dispose() {
    search.dispose();
    super.dispose();
  }

  void changeQuantity(Dish dish, int delta) => setState(() {
    final next = (basket[dish.id] ?? 0) + delta;
    if (next <= 0) {
      basket.remove(dish.id);
    } else {
      basket[dish.id] = next.clamp(1, 99);
    }
  });

  Future<void> showDish(Dish dish) async {
    final quantity = await showModalBottomSheet<int>(
      context: context,
      isScrollControlled: true,
      useSafeArea: true,
      backgroundColor: Colors.white,
      constraints: const BoxConstraints(maxWidth: 640),
      shape: const RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(top: Radius.circular(28)),
      ),
      builder: (_) =>
          DishDetail(dish: dish, available: 99 - (basket[dish.id] ?? 0)),
    );
    if (!mounted || quantity == null) return;
    changeQuantity(dish, quantity);
  }

  void showBasket() {
    showModalBottomSheet<void>(
      context: context,
      isScrollControlled: true,
      useSafeArea: true,
      constraints: const BoxConstraints(maxWidth: 640),
      backgroundColor: Colors.white,
      builder: (sheetContext) => StatefulBuilder(
        builder: (context, updateSheet) => ConstrainedBox(
          constraints: BoxConstraints(
            maxHeight: MediaQuery.sizeOf(context).height * .85,
          ),
          child: Padding(
            padding: const EdgeInsets.all(24),
            child: Column(
              mainAxisSize: MainAxisSize.min,
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Row(
                  children: [
                    const Expanded(
                      child: Text(
                        'Tu selección',
                        style: TextStyle(
                          fontSize: 26,
                          fontWeight: FontWeight.w800,
                        ),
                      ),
                    ),
                    IconButton(
                      onPressed: () => Navigator.pop(sheetContext),
                      tooltip: 'Cerrar selección',
                      icon: const Icon(Icons.close),
                    ),
                  ],
                ),
                const SizedBox(height: 8),
                const Text(
                  'Carta de demostración. No se envían pedidos ni se realizan cobros.',
                  style: TextStyle(color: muted),
                ),
                const SizedBox(height: 18),
                Flexible(
                  child: ListView(
                    shrinkWrap: true,
                    children: [
                      if (basket.isEmpty)
                        const Padding(
                          padding: EdgeInsets.all(24),
                          child: Text(
                            'Tu selección está vacía. Explora la carta para comenzar.',
                          ),
                        ),
                      for (final dish in dishes.where(
                        (d) => basket.containsKey(d.id),
                      ))
                        Padding(
                          padding: const EdgeInsets.symmetric(vertical: 10),
                          child: Row(
                            children: [
                              ClipRRect(
                                borderRadius: BorderRadius.circular(12),
                                child: SizedBox(
                                  width: 52,
                                  height: 52,
                                  child: DishPhoto(dish: dish),
                                ),
                              ),
                              const SizedBox(width: 10),
                              Expanded(
                                child: Column(
                                  crossAxisAlignment: CrossAxisAlignment.start,
                                  children: [
                                    Text(
                                      dish.name,
                                      style: const TextStyle(
                                        fontWeight: FontWeight.w700,
                                      ),
                                    ),
                                    Text(money(dish.cents * basket[dish.id]!)),
                                  ],
                                ),
                              ),
                              QuantityControl(
                                quantity: basket[dish.id]!,
                                onMinus: () {
                                  changeQuantity(dish, -1);
                                  updateSheet(() {});
                                },
                                onPlus: () {
                                  changeQuantity(dish, 1);
                                  updateSheet(() {});
                                },
                              ),
                            ],
                          ),
                        ),
                    ],
                  ),
                ),
                const Divider(height: 32),
                Row(
                  mainAxisAlignment: MainAxisAlignment.spaceBetween,
                  children: [
                    const Text('Total estimado'),
                    Text(
                      money(total),
                      style: const TextStyle(
                        fontSize: 26,
                        fontWeight: FontWeight.w800,
                      ),
                    ),
                  ],
                ),
                const SizedBox(height: 16),
                SizedBox(
                  width: double.infinity,
                  child: FilledButton(
                    onPressed: () => Navigator.pop(sheetContext),
                    child: const Text('Seguir explorando'),
                  ),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final visible = dishes
        .where(
          (dish) =>
              (category == 'Todo' || dish.category == category) &&
              normalize('${dish.name} ${dish.description} ${dish.ingredients}')
                  .contains(normalize(query)),
        )
        .toList();
    return Scaffold(
      bottomNavigationBar: units == 0
          ? null
          : SafeArea(
              top: false,
              child: Container(
                decoration: const BoxDecoration(
                  color: Colors.white,
                  border: Border(top: BorderSide(color: Color(0xFFE8E6E1))),
                ),
                padding: const EdgeInsets.symmetric(
                  horizontal: 20,
                  vertical: 12,
                ),
                child: Center(
                  heightFactor: 1,
                  child: ConstrainedBox(
                    constraints: const BoxConstraints(maxWidth: 1160),
                    child: SizedBox(
                      width: double.infinity,
                      child: FilledButton(
                        onPressed: showBasket,
                        child: Row(
                          children: [
                            const Icon(Icons.shopping_bag_outlined),
                            const SizedBox(width: 12),
                            Expanded(child: Text('Ver selección ($units)')),
                            Text(money(total)),
                          ],
                        ),
                      ),
                    ),
                  ),
                ),
              ),
            ),
      body: CustomScrollView(
        slivers: [
          SliverToBoxAdapter(
            child: Center(
              child: ConstrainedBox(
                constraints: const BoxConstraints(maxWidth: 1240),
                child: Padding(
                  padding: const EdgeInsets.symmetric(horizontal: 24),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Padding(
                        padding: const EdgeInsets.symmetric(vertical: 24),
                        child: Row(
                          children: [
                            Container(
                              width: 42,
                              height: 42,
                              decoration: BoxDecoration(
                                color: accent,
                                borderRadius: BorderRadius.circular(13),
                              ),
                              child: const Icon(
                                Icons.restaurant,
                                color: Colors.white,
                                size: 23,
                              ),
                            ),
                            const SizedBox(width: 12),
                            const Expanded(
                              child: Column(
                                crossAxisAlignment: CrossAxisAlignment.start,
                                children: [
                                  Text(
                                    'YURAKUNA',
                                    style: TextStyle(
                                      fontWeight: FontWeight.w900,
                                      fontSize: 20,
                                      letterSpacing: 1.5,
                                    ),
                                  ),
                                  Text(
                                    'Sabor que se comparte',
                                    style: TextStyle(
                                      color: muted,
                                      fontSize: 12,
                                    ),
                                  ),
                                ],
                              ),
                            ),
                            IconButton(
                              onPressed: showBasket,
                              tooltip: 'Ver selección',
                              icon: Badge(
                                isLabelVisible: units > 0,
                                label: Text('$units'),
                                child: const Icon(Icons.shopping_bag_outlined),
                              ),
                            ),
                          ],
                        ),
                      ),
                      const HeroBanner(),
                      const SizedBox(height: 34),
                      LayoutBuilder(
                        builder: (context, constraints) {
                          final heading = Column(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                              const Text(
                                'Hecho para disfrutar.',
                                style: TextStyle(
                                  fontSize: 29,
                                  fontWeight: FontWeight.w800,
                                  letterSpacing: -.9,
                                ),
                              ),
                              const SizedBox(height: 6),
                              Text(
                                '${visible.length} propuestas · Encuentra tu próximo favorito',
                                style: const TextStyle(color: muted),
                              ),
                            ],
                          );
                          final field = TextField(
                            controller: search,
                            onChanged: (value) => setState(() => query = value),
                            decoration: InputDecoration(
                              hintText: 'Buscar un plato o ingrediente',
                              prefixIcon: const Icon(Icons.search),
                              suffixIcon: query.isEmpty
                                  ? null
                                  : IconButton(
                                      tooltip: 'Limpiar búsqueda',
                                      onPressed: () {
                                        search.clear();
                                        setState(() => query = '');
                                      },
                                      icon: const Icon(Icons.close),
                                    ),
                            ),
                          );
                          if (constraints.maxWidth < 700) {
                            return Column(
                              crossAxisAlignment: CrossAxisAlignment.start,
                              children: [
                                heading,
                                const SizedBox(height: 18),
                                field,
                              ],
                            );
                          }
                          return Row(
                            children: [
                              Expanded(child: heading),
                              SizedBox(width: 340, child: field),
                            ],
                          );
                        },
                      ),
                      const SizedBox(height: 24),
                      SingleChildScrollView(
                        scrollDirection: Axis.horizontal,
                        child: Row(
                          children: categories
                              .map(
                                (item) => Padding(
                                  padding: const EdgeInsets.only(right: 9),
                                  child: ChoiceChip(
                                    label: Text(item),
                                    selected: category == item,
                                    showCheckmark: false,
                                    selectedColor: ink,
                                    backgroundColor: Colors.white,
                                    labelStyle: TextStyle(
                                      color: category == item
                                          ? Colors.white
                                          : muted,
                                      fontWeight: FontWeight.w600,
                                    ),
                                    side: BorderSide(
                                      color: category == item
                                          ? ink
                                          : const Color(0xFFE6E5E0),
                                    ),
                                    padding: const EdgeInsets.symmetric(
                                      horizontal: 15,
                                      vertical: 13,
                                    ),
                                    shape: RoundedRectangleBorder(
                                      borderRadius: BorderRadius.circular(30),
                                    ),
                                    onSelected: (_) =>
                                        setState(() => category = item),
                                  ),
                                ),
                              )
                              .toList(),
                        ),
                      ),
                      const SizedBox(height: 24),
                      if (visible.isEmpty)
                        Center(
                          child: Padding(
                            padding: const EdgeInsets.symmetric(vertical: 60),
                            child: Column(
                              children: [
                                const Icon(
                                  Icons.search_off,
                                  color: muted,
                                  size: 40,
                                ),
                                const SizedBox(height: 12),
                                const Text(
                                  'No encontramos ese plato',
                                  style: TextStyle(
                                    fontSize: 21,
                                    fontWeight: FontWeight.bold,
                                  ),
                                ),
                                TextButton(
                                  onPressed: () {
                                    search.clear();
                                    setState(() {
                                      query = '';
                                      category = 'Todo';
                                    });
                                  },
                                  child: const Text('Ver toda la carta'),
                                ),
                              ],
                            ),
                          ),
                        ),
                      LayoutBuilder(
                        builder: (context, constraints) {
                          final columns = constraints.maxWidth >= 1080
                              ? 4
                              : constraints.maxWidth >= 650
                              ? 2
                              : 1;
                          final width =
                              (constraints.maxWidth - (columns - 1) * 20) /
                              columns;
                          return Wrap(
                            spacing: 20,
                            runSpacing: 24,
                            children: visible
                                .map(
                                  (dish) => SizedBox(
                                    width: width,
                                    child: DishCard(
                                      dish: dish,
                                      favorite: favorites.contains(dish.id),
                                      quantity: basket[dish.id] ?? 0,
                                      onOpen: () => showDish(dish),
                                      onAdd: () => changeQuantity(dish, 1),
                                      onFavorite: () => setState(() {
                                        if (!favorites.add(dish.id)) {
                                          favorites.remove(dish.id);
                                        }
                                      }),
                                    ),
                                  ),
                                )
                                .toList(),
                          );
                        },
                      ),
                      const SizedBox(height: 36),
                      Container(
                        width: double.infinity,
                        padding: const EdgeInsets.all(20),
                        decoration: BoxDecoration(
                          color: const Color(0xFFF0EEE8),
                          borderRadius: BorderRadius.circular(18),
                        ),
                        child: const Row(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Icon(Icons.info_outline, size: 20, color: muted),
                            SizedBox(width: 12),
                            Expanded(
                              child: Text(
                                'Una muestra de lo que viene. Platos, fotos y precios ilustrativos. Si tienes una alergia, consulta con el restaurante antes de pedir.',
                                style: TextStyle(color: muted, height: 1.6),
                              ),
                            ),
                          ],
                        ),
                      ),
                      const Padding(
                        padding: EdgeInsets.symmetric(vertical: 28),
                        child: Row(
                          mainAxisAlignment: MainAxisAlignment.spaceBetween,
                          children: [
                            Text(
                              'YURAKUNA · Demo',
                              style: TextStyle(color: muted, fontSize: 11),
                            ),
                            Text(
                              'Con Adbana',
                              style: TextStyle(color: muted, fontSize: 11),
                            ),
                          ],
                        ),
                      ),
                    ],
                  ),
                ),
              ),
            ),
          ),
        ],
      ),
    );
  }
}
