import 'package:flutter/material.dart';

import 'palette.dart';
import 'menu_data.dart';
import 'dish_model_page.dart';

class HeroBanner extends StatelessWidget {
  const HeroBanner({super.key});
  @override
  Widget build(BuildContext context) => ClipRRect(
    borderRadius: BorderRadius.circular(26),
    child: Container(
      color: ink,
      child: LayoutBuilder(
        builder: (context, constraints) {
          final compact = constraints.maxWidth < 650;
          final copy = Padding(
            padding: EdgeInsets.all(compact ? 26 : 40),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              mainAxisAlignment: MainAxisAlignment.center,
              children: [
                const Text(
                  'A TU RITMO. A TU GUSTO.',
                  style: TextStyle(
                    color: Color(0xFFFFAA91),
                    fontSize: 11,
                    fontWeight: FontWeight.w700,
                    letterSpacing: 2,
                  ),
                ),
                const SizedBox(height: 16),
                Text(
                  'Una buena mesa.\nUn gran momento.',
                  style: TextStyle(
                    color: Colors.white,
                    fontSize: compact ? 36 : 46,
                    height: 1.08,
                    fontWeight: FontWeight.w800,
                    letterSpacing: -1.7,
                  ),
                ),
                const SizedBox(height: 18),
                const Text(
                  'Explora la carta y descubre algo que te encante.',
                  style: TextStyle(
                    color: Color(0xFFC0C0BB),
                    fontSize: 15,
                    height: 1.6,
                  ),
                ),
                const SizedBox(height: 22),
                const Row(
                  children: [
                    Icon(
                      Icons.restaurant_menu,
                      size: 17,
                      color: Color(0xFFFFAA91),
                    ),
                    SizedBox(width: 8),
                    Expanded(
                      child: Text(
                        'Cuatro sabores para empezar',
                        style: TextStyle(color: Colors.white, fontSize: 12),
                      ),
                    ),
                  ],
                ),
              ],
            ),
          );
          if (compact) return copy;
          return SizedBox(
            width: double.infinity,
            child: Stack(
              children: [
                Positioned.fill(
                  child: Align(
                    alignment: Alignment.centerRight,
                    child: FractionallySizedBox(
                      widthFactor: .4,
                      child: Stack(
                        fit: StackFit.expand,
                        children: [
                          DishPhoto(dish: dishes.first),
                          const DecoratedBox(
                            decoration: BoxDecoration(
                              gradient: LinearGradient(
                                colors: [ink, Colors.transparent],
                                stops: [0, .6],
                              ),
                            ),
                          ),
                        ],
                      ),
                    ),
                  ),
                ),
                SizedBox(width: constraints.maxWidth * .6, child: copy),
              ],
            ),
          );
        },
      ),
    ),
  );
}

class DishPhoto extends StatelessWidget {
  const DishPhoto({super.key, required this.dish});
  final Dish dish;
  @override
  Widget build(BuildContext context) => Image.asset(
    'assets/images/${dish.id}.webp',
    fit: BoxFit.cover,
    filterQuality: FilterQuality.medium,
    semanticLabel: 'Fotografía ilustrativa de ${dish.name}',
    errorBuilder: (_, _, _) => const ColoredBox(
      color: Color(0xFFECE9E2),
      child: Center(
        child: Icon(Icons.restaurant_outlined, color: muted, size: 42),
      ),
    ),
  );
}

class DishCard extends StatelessWidget {
  const DishCard({
    super.key,
    required this.dish,
    required this.favorite,
    required this.quantity,
    required this.onOpen,
    required this.onAdd,
    required this.onFavorite,
  });
  final Dish dish;
  final bool favorite;
  final int quantity;
  final VoidCallback onOpen, onAdd, onFavorite;
  @override
  Widget build(BuildContext context) => Material(
    color: Colors.white,
    shape: RoundedRectangleBorder(
      borderRadius: BorderRadius.circular(22),
      side: const BorderSide(color: Color(0xFFEAE7E1)),
    ),
    clipBehavior: Clip.antiAlias,
    child: Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Stack(
          children: [
            InkWell(
              onTap: onOpen,
              child: AspectRatio(
                aspectRatio: 1.35,
                child: DishPhoto(dish: dish),
              ),
            ),
            Positioned(
              top: 12,
              left: 12,
              child: Container(
                padding: const EdgeInsets.symmetric(
                  horizontal: 10,
                  vertical: 7,
                ),
                decoration: BoxDecoration(
                  color: Colors.white,
                  borderRadius: BorderRadius.circular(20),
                ),
                child: Text(
                  dish.tag,
                  style: const TextStyle(
                    fontSize: 10,
                    fontWeight: FontWeight.w700,
                  ),
                ),
              ),
            ),
            Positioned(
              top: 8,
              right: 8,
              child: IconButton.filledTonal(
                tooltip: favorite
                    ? 'Quitar favorito: ${dish.name}'
                    : 'Guardar favorito: ${dish.name}',
                style: IconButton.styleFrom(
                  backgroundColor: Colors.white,
                  foregroundColor: favorite ? accent : ink,
                ),
                onPressed: onFavorite,
                icon: Icon(
                  favorite ? Icons.favorite : Icons.favorite_border,
                  size: 19,
                ),
              ),
            ),
          ],
        ),
        Padding(
          padding: const EdgeInsets.all(18),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(
                dish.category.toUpperCase(),
                style: const TextStyle(
                  fontSize: 9,
                  letterSpacing: 1.5,
                  color: muted,
                  fontWeight: FontWeight.w600,
                ),
              ),
              const SizedBox(height: 8),
              InkWell(
                onTap: onOpen,
                child: Padding(
                  padding: const EdgeInsets.symmetric(vertical: 4),
                  child: Text(
                    dish.name,
                    style: const TextStyle(
                      fontSize: 20,
                      fontWeight: FontWeight.w800,
                      letterSpacing: -.5,
                    ),
                  ),
                ),
              ),
              const SizedBox(height: 8),
              SizedBox(
                height: 60,
                child: Text(
                  dish.description,
                  maxLines: 3,
                  overflow: TextOverflow.ellipsis,
                  style: const TextStyle(
                    color: muted,
                    fontSize: 13,
                    height: 1.5,
                  ),
                ),
              ),
              const SizedBox(height: 16),
              Row(
                children: [
                  Expanded(
                    child: Text(
                      money(dish.cents),
                      style: const TextStyle(
                        fontSize: 22,
                        fontWeight: FontWeight.w800,
                      ),
                    ),
                  ),
                  IconButton.filled(
                    tooltip: 'Añadir ${dish.name}',
                    onPressed: quantity >= 99 ? null : onAdd,
                    style: IconButton.styleFrom(
                      backgroundColor: ink,
                      foregroundColor: Colors.white,
                      minimumSize: const Size(46, 46),
                    ),
                    icon: const Icon(Icons.add),
                  ),
                ],
              ),
              TextButton(
                onPressed: onOpen,
                style: TextButton.styleFrom(padding: EdgeInsets.zero),
                child: const Text(
                  'Conocer el plato →',
                  style: TextStyle(fontSize: 12),
                ),
              ),
            ],
          ),
        ),
      ],
    ),
  );
}

class DishDetail extends StatefulWidget {
  const DishDetail({super.key, required this.dish, required this.available});
  final Dish dish;
  final int available;
  @override
  State<DishDetail> createState() => _DishDetailState();
}

class _DishDetailState extends State<DishDetail> {
  int quantity = 1;
  @override
  Widget build(BuildContext context) {
    final dish = widget.dish;
    return ConstrainedBox(
      constraints: BoxConstraints(
        maxHeight: MediaQuery.sizeOf(context).height * .94,
      ),
      child: SingleChildScrollView(
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Stack(
              children: [
                AspectRatio(aspectRatio: 1.8, child: DishPhoto(dish: dish)),
                Positioned(
                  top: 12,
                  right: 12,
                  child: IconButton.filledTonal(
                    onPressed: () => Navigator.pop(context),
                    tooltip: 'Cerrar detalle',
                    icon: const Icon(Icons.close),
                  ),
                ),
              ],
            ),
            Padding(
              padding: const EdgeInsets.all(24),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    dish.category.toUpperCase(),
                    style: const TextStyle(
                      color: accent,
                      fontWeight: FontWeight.w700,
                      letterSpacing: 1.5,
                      fontSize: 11,
                    ),
                  ),
                  const SizedBox(height: 10),
                  Text(
                    dish.name,
                    style: const TextStyle(
                      fontSize: 30,
                      fontWeight: FontWeight.w800,
                      letterSpacing: -1,
                    ),
                  ),
                  const SizedBox(height: 12),
                  Text(
                    dish.description,
                    style: const TextStyle(color: muted, height: 1.7),
                  ),
                  const SizedBox(height: 24),
                  const Text(
                    'Ingredientes',
                    style: TextStyle(fontWeight: FontWeight.bold, fontSize: 16),
                  ),
                  const SizedBox(height: 8),
                  Text(
                    dish.ingredients,
                    style: const TextStyle(color: muted, height: 1.6),
                  ),
                  const SizedBox(height: 16),
                  Text(
                    dish.allergens,
                    style: const TextStyle(
                      fontSize: 12,
                      fontWeight: FontWeight.w600,
                    ),
                  ),
                  const SizedBox(height: 24),
                  if (dish.modelAsset != null)
                    OutlinedButton.icon(
                      icon: const Icon(Icons.view_in_ar_outlined),
                      label: const Text('Ver en 3D y realidad aumentada'),
                      onPressed: () => Navigator.of(context).push<void>(
                        MaterialPageRoute(
                          builder: (_) => DishModelPage(dish: dish),
                        ),
                      ),
                    )
                  else
                    const Text(
                      'Foto de referencia. Este plato aún no tiene modelo 3D.',
                      style: TextStyle(color: muted, fontSize: 12),
                    ),
                  const SizedBox(height: 24),
                  Row(
                    mainAxisAlignment: MainAxisAlignment.spaceBetween,
                    children: [
                      const Text(
                        'Cantidad',
                        style: TextStyle(fontWeight: FontWeight.w600),
                      ),
                      QuantityControl(
                        quantity: quantity,
                        onMinus: quantity <= 1
                            ? null
                            : () => setState(() => quantity--),
                        onPlus: quantity >= widget.available
                            ? null
                            : () => setState(() => quantity++),
                      ),
                    ],
                  ),
                  const SizedBox(height: 20),
                  SizedBox(
                    width: double.infinity,
                    child: FilledButton(
                      onPressed: widget.available == 0
                          ? null
                          : () => Navigator.pop(context, quantity),
                      child: Text(
                        widget.available == 0
                            ? 'Límite de 99 unidades alcanzado'
                            : 'Añadir a mi selección · ${money(dish.cents * quantity)}',
                      ),
                    ),
                  ),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class QuantityControl extends StatelessWidget {
  const QuantityControl({
    super.key,
    required this.quantity,
    required this.onMinus,
    required this.onPlus,
  });
  final int quantity;
  final VoidCallback? onMinus, onPlus;
  @override
  Widget build(BuildContext context) => Row(
    mainAxisSize: MainAxisSize.min,
    children: [
      IconButton(
        onPressed: onMinus,
        tooltip: 'Reducir cantidad',
        icon: const Icon(Icons.remove_circle_outline),
      ),
      SizedBox(
        width: 25,
        child: Text(
          '$quantity',
          textAlign: TextAlign.center,
          style: const TextStyle(fontWeight: FontWeight.bold),
        ),
      ),
      IconButton(
        onPressed: quantity >= 99 ? null : onPlus,
        tooltip: 'Aumentar cantidad',
        icon: const Icon(Icons.add_circle_outline),
      ),
    ],
  );
}
