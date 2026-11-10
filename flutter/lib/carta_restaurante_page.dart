import 'package:flutter/material.dart';

import 'carta_api.dart';
import 'carta_widgets.dart';
import 'checkout_page.dart';
import 'menu_data.dart';
import 'dish_model_page.dart';

class CartaRestaurantePage extends StatefulWidget {
  const CartaRestaurantePage({super.key, required this.slug});
  final String slug;
  @override
  State<CartaRestaurantePage> createState() => _CartaRestaurantePageState();
}

class _CartaRestaurantePageState extends State<CartaRestaurantePage> {
  final api = CartaApi();
  late Future<Map<String, dynamic>> result;
  final Map<int, int> basket = {};
  @override
  void initState() {
    super.initState();
    load();
  }

  void load() =>
      result = api.request('carta/${Uri.encodeComponent(widget.slug)}');
  @override
  void dispose() {
    api.close();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => Scaffold(
    appBar: AppBar(title: const Text('Carta del restaurante')),
    body: FutureBuilder<Map<String, dynamic>>(
      future: result,
      builder: (context, snapshot) {
        if (snapshot.connectionState != ConnectionState.done) {
          return const Center(child: CircularProgressIndicator());
        }
        if (snapshot.hasError) {
          return RetryState(
            message: 'La carta no está disponible. Puede que el restaurante esté desactivado o no haya conexión.',
            onRetry: () => setState(load),
          );
        }
        final data = snapshot.data!;
        final restaurant = data['restaurante'] as Map<String, dynamic>;
        final categories = data['carta'] as List;
        final products = categories
            .expand((c) => c['productos'] as List)
            .cast<Map<String, dynamic>>()
            .toList();
        final total = products.fold<int>(
          0,
          (sum, p) => sum + priceCents(p['precio']) * (basket[p['id']] ?? 0),
        );
        final config =
            restaurant['configuracion'] as Map<String, dynamic>? ?? {};
        return Center(
          child: ConstrainedBox(
            constraints: const BoxConstraints(maxWidth: 1000),
            child: Column(
              children: [
                Expanded(
                  child: ListView(
                    padding: const EdgeInsets.all(24),
                    children: [
                      Text(
                        restaurant['nombre'],
                        style: const TextStyle(
                          fontSize: 30,
                          fontWeight: FontWeight.bold,
                        ),
                      ),
                      Text(
                        '${config['descripcion'] ?? ''}\n${config['direccion'] ?? ''}\n${config['horario'] ?? ''}',
                      ),
                      const SizedBox(height: 20),
                      if (products.isEmpty)
                        const Text(
                          'Este restaurante todavía no tiene productos disponibles.',
                        ),
                      for (final category in categories) ...[
                        Padding(
                          padding: const EdgeInsets.symmetric(vertical: 16),
                          child: Text(
                            category['nombre'],
                            style: const TextStyle(
                              fontSize: 22,
                              fontWeight: FontWeight.bold,
                            ),
                          ),
                        ),
                        for (final p in category['productos'])
                          Card(
                            child: Padding(
                              padding: const EdgeInsets.all(16),
                              child: Column(
                                crossAxisAlignment: CrossAxisAlignment.start,
                                children: [
                                  if (p['imagen'] != null &&
                                      p['imagen'].toString().isNotEmpty)
                                    ClipRRect(
                                      borderRadius: BorderRadius.circular(14),
                                      child: Image.network(
                                        webHtmlElementStrategy:
                                            WebHtmlElementStrategy.fallback,
                                        Uri.parse(api.baseUrl)
                                            .resolve(p['imagen'])
                                            .toString(),
                                        height: 160,
                                        width: double.infinity,
                                        fit: BoxFit.cover,
                                        errorBuilder: (_, _, _) =>
                                            const SizedBox(
                                              height: 50,
                                              child: Icon(Icons.restaurant),
                                            ),
                                      ),
                                    ),
                                  Text(
                                    p['nombre'],
                                    style: const TextStyle(
                                      fontSize: 19,
                                      fontWeight: FontWeight.w600,
                                    ),
                                  ),
                                  Text(p['descripcion'] ?? ''),
                                  if (p['modelo_3d'] != null)
                                    TextButton.icon(
                                      icon: const Icon(Icons.view_in_ar),
                                      label: const Text('Explorar en 3D / AR'),
                                      onPressed: () => Navigator.push(
                                        context,
                                        MaterialPageRoute<void>(
                                          builder: (_) => DishModelPage(
                                            dish: Dish(
                                              p['id'].toString(),
                                              p['nombre'],
                                              category['nombre'],
                                              p['descripcion'] ?? '',
                                              0,
                                              '',
                                              '',
                                              '',
                                              modelAsset: Uri.parse(api.baseUrl)
                                                  .resolve(p['modelo_3d'])
                                                  .toString(),
                                            ),
                                          ),
                                        ),
                                      ),
                                    ),

                                  Row(
                                    children: [
                                      Expanded(
                                        child: Text('S/ ${p['precio']}'),
                                      ),
                                      if (p['disponible'] == true) ...[
                                        IconButton(
                                          tooltip: 'Quitar',
                                          onPressed: (basket[p['id']] ?? 0) > 0
                                              ? () => setState(
                                                  () => basket[p['id']] =
                                                      (basket[p['id']] ?? 0) -
                                                      1,
                                                )
                                              : null,
                                          icon: const Icon(Icons.remove),
                                        ),
                                        Text('${basket[p['id']] ?? 0}'),
                                        IconButton(
                                          tooltip: 'Añadir',
                                          onPressed:
                                              (basket[p['id']] ?? 0) < 100
                                              ? () => setState(
                                                  () => basket[p['id']] =
                                                      (basket[p['id']] ?? 0) +
                                                      1,
                                                )
                                              : null,
                                          icon: const Icon(Icons.add),
                                        ),
                                      ] else
                                        const Text('Agotado'),
                                    ],
                                  ),
                                ],
                              ),
                            ),
                          ),
                      ],
                    ],
                  ),
                ),
                SafeArea(
                  top: false,
                  child: Padding(
                    padding: const EdgeInsets.all(20),
                    child: FilledButton(
                      onPressed: total > 0
                          ? () async {
                              final done = await Navigator.push<bool>(
                                context,
                                MaterialPageRoute(
                                  builder: (_) => CheckoutPage(
                                    slug: widget.slug,
                                    restaurant: restaurant,
                                    items: [
                                      for (final entry in basket.entries)
                                        if (entry.value > 0)
                                          {
                                            'producto_id': entry.key,
                                            'cantidad': entry.value,
                                          },
                                    ],
                                    total: total,
                                  ),
                                ),
                              );
                              if (mounted && done == true) {
                                setState(() {
                                  basket.clear();
                                  load();
                                });
                              }
                            }
                          : null,
                      child: Text(
                        'Continuar · S/ ${(total / 100).toStringAsFixed(2)}',
                      ),
                    ),
                  ),
                ),
              ],
            ),
          ),
        );
      },
    ),
  );
}
