import 'package:flutter/material.dart';

import 'carta_api.dart';
import 'palette.dart';
import 'carta_widgets.dart';
import 'carta_restaurante_page.dart';

class RestaurantesPage extends StatefulWidget {
  const RestaurantesPage({super.key});
  @override
  State<RestaurantesPage> createState() => _RestaurantesPageState();
}

class _RestaurantesPageState extends State<RestaurantesPage> {
  final api = CartaApi();
  final search = TextEditingController();
  late Future<Map<String, dynamic>> result;
  int page = 1;
  String comida = '';
  static const comidas = [
    ('Todas', '', Icons.restaurant_menu, Color(0xFFFFE5DC)),
    ('Cafeterías', 'café', Icons.coffee, Color(0xFFFFE8CC)),
    ('Sándwiches', 'sándwich', Icons.lunch_dining, Color(0xFFFFEAA0)),
    ('Pollerías', 'pollo', Icons.dinner_dining, Color(0xFFFFDCC4)),
    ('Postres', 'postre', Icons.cake, Color(0xFFFFDDEE)),
    ('Hamburguesas', 'hamburguesa', Icons.lunch_dining, Color(0xFFFFDF9C)),
    ('Pizzas', 'pizza', Icons.local_pizza, Color(0xFFFFD5CD)),
    ('Criolla', 'criolla', Icons.rice_bowl, Color(0xFFDCECCD)),
  ];
  @override
  void initState() {
    super.initState();
    load();
    final slug = Uri.base.queryParameters['restaurante'];
    if (slug != null && RegExp(r'^[a-z0-9-]+$').hasMatch(slug)) {
      WidgetsBinding.instance.addPostFrameCallback((_) {
        if (mounted) open(slug);
      });
    }
  }

  void load() => result = api.request(
    'restaurantes?directorio=1&comida=${Uri.encodeComponent(comida)}&q=${Uri.encodeComponent(search.text.trim())}&page=$page',
  );
  void open(String slug) => Navigator.of(context).push(
    MaterialPageRoute<void>(builder: (_) => CartaRestaurantePage(slug: slug)),
  );
  @override
  void dispose() {
    api.close();
    search.dispose();
    super.dispose();
  }

  void searchFromFirstPage() => setState(() {
    page = 1;
    load();
  });

  @override
  Widget build(BuildContext context) => Scaffold(
    backgroundColor: paper,
    appBar: AppBar(
      backgroundColor: paper,
      title: const Row(
        children: [
          CircleAvatar(
            backgroundColor: accent,
            foregroundColor: Colors.white,
            child: Icon(Icons.restaurant_menu),
          ),
          SizedBox(width: 10),
          Text('Adbana', style: TextStyle(fontWeight: FontWeight.w800)),
        ],
      ),
    ),
    body: Align(
      alignment: Alignment.topCenter,
      child: ConstrainedBox(
        constraints: const BoxConstraints(maxWidth: 1040),
        child: ListView(
          padding: const EdgeInsets.fromLTRB(20, 16, 20, 32),
          children: [
            Container(
              padding: const EdgeInsets.all(24),
              decoration: BoxDecoration(
                color: ink,
                borderRadius: BorderRadius.circular(24),
              ),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  const Text(
                    '¿Qué se te antoja?',
                    style: TextStyle(
                      color: Colors.white,
                      fontSize: 30,
                      fontWeight: FontWeight.w800,
                    ),
                  ),
                  const SizedBox(height: 6),
                  const Text(
                    'Explora las cartas y elige dónde comer.',
                    style: TextStyle(color: Colors.white70),
                  ),
                  const SizedBox(height: 18),
                  TextField(
                    controller: search,
                    textInputAction: TextInputAction.search,
                    onSubmitted: (_) => searchFromFirstPage(),
                    decoration: InputDecoration(
                      hintText: 'Buscar restaurante',
                      filled: true,
                      fillColor: Colors.white,
                      suffixIcon: IconButton(
                        tooltip: 'Buscar',
                        onPressed: searchFromFirstPage,
                        icon: const Icon(Icons.search),
                      ),
                    ),
                  ),
                ],
              ),
            ),
            const SizedBox(height: 28),
            Text('Comidas', style: Theme.of(context).textTheme.headlineSmall),
            const SizedBox(height: 12),
            SingleChildScrollView(
              scrollDirection: Axis.horizontal,
              child: Row(
                children: [
                  for (final (nombre, filtro, icono, color) in comidas)
                    Padding(
                      padding: const EdgeInsets.only(right: 10),
                      child: Column(
                        children: [
                          ChoiceChip(
                            showCheckmark: false,
                            tooltip: nombre,
                            backgroundColor: color,
                            selectedColor: ink,
                            side: BorderSide.none,
                            shape: RoundedRectangleBorder(
                              borderRadius: BorderRadius.circular(18),
                            ),
                            padding: const EdgeInsets.all(14),
                            label: Icon(
                              icono,
                              size: 29,
                              color: comida == filtro ? Colors.white : ink,
                            ),
                            selected: comida == filtro,
                            onSelected: (_) {
                              comida = filtro;
                              searchFromFirstPage();
                            },
                          ),
                          const SizedBox(height: 6),
                          Text(
                            nombre,
                            style: TextStyle(
                              fontWeight: comida == filtro
                                  ? FontWeight.w800
                                  : FontWeight.w500,
                            ),
                          ),
                        ],
                      ),
                    ),
                ],
              ),
            ),
            const SizedBox(height: 24),
            Text(
              'Restaurantes',
              style: Theme.of(context).textTheme.headlineSmall,
            ),
            const SizedBox(height: 12),
            FutureBuilder<Map<String, dynamic>>(
              future: result,
              builder: (context, snapshot) {
                if (snapshot.connectionState != ConnectionState.done) {
                  return const LinearProgressIndicator();
                }
                if (snapshot.hasError) {
                  return RetryState(
                    message: 'No pudimos cargar las cartas.',
                    onRetry: () => setState(load),
                  );
                }
                final data = snapshot.data!;
                final entries = data['data'] as List;
                final lastPage = data['last_page'] as int;
                if (entries.isEmpty) {
                  final filtered =
                      search.text.trim().isNotEmpty || comida.isNotEmpty;
                  return Card(
                    child: Padding(
                      padding: const EdgeInsets.all(24),
                      child: Column(
                        children: [
                          const Icon(
                            Icons.menu_book_outlined,
                            color: accent,
                            size: 36,
                          ),
                          const SizedBox(height: 12),
                          Text(
                            filtered
                                ? 'No encontramos restaurantes con estos filtros'
                                : 'Todavía no hay restaurantes disponibles',
                          ),
                          TextButton(
                            onPressed: () {
                              search.clear();
                              comida = '';
                              searchFromFirstPage();
                            },
                            child: Text(filtered ? 'Ver todos' : 'Actualizar'),
                          ),
                        ],
                      ),
                    ),
                  );
                }
                return Column(
                  children: [
                    LayoutBuilder(
                      builder: (context, constraints) {
                        const gap = 14.0;
                        final width = constraints.maxWidth >= 680
                            ? (constraints.maxWidth - gap) / 2
                            : constraints.maxWidth;
                        return Wrap(
                          spacing: gap,
                          runSpacing: gap,
                          children: [
                            for (final item in entries)
                              SizedBox(
                                width: width,
                                child: Card(
                                  margin: EdgeInsets.zero,
                                  color: Colors.white,
                                  elevation: 0,
                                  shape: RoundedRectangleBorder(
                                    borderRadius: BorderRadius.circular(20),
                                    side: const BorderSide(
                                      color: Color(0xFFECE5DF),
                                    ),
                                  ),
                                  child: ListTile(
                                    shape: RoundedRectangleBorder(
                                      borderRadius: BorderRadius.circular(20),
                                    ),
                                    contentPadding: const EdgeInsets.all(16),
                                    leading: const CircleAvatar(
                                      backgroundColor: Color(0xFFFFE5DC),
                                      foregroundColor: accent,
                                      child: Icon(Icons.restaurant_outlined),
                                    ),
                                    title: Text(
                                      item['nombre'] as String,
                                      style: const TextStyle(
                                        color: ink,
                                        fontWeight: FontWeight.w800,
                                      ),
                                    ),
                                    subtitle: const Text(
                                      'Ver carta',
                                      style: TextStyle(
                                        color: accent,
                                        fontWeight: FontWeight.w600,
                                      ),
                                    ),
                                    trailing: const Icon(
                                      Icons.arrow_forward_rounded,
                                      color: accent,
                                    ),
                                    onTap: () => open(item['slug'] as String),
                                  ),
                                ),
                              ),
                          ],
                        );
                      },
                    ),
                    if (lastPage > 1)
                      Row(
                        mainAxisAlignment: MainAxisAlignment.center,
                        children: [
                          IconButton(
                            tooltip: 'Página anterior',
                            onPressed: page > 1
                                ? () => setState(() {
                                    page--;
                                    load();
                                  })
                                : null,
                            icon: const Icon(Icons.chevron_left),
                          ),
                          Text('$page / $lastPage'),
                          IconButton(
                            tooltip: 'Página siguiente',
                            onPressed: page < lastPage
                                ? () => setState(() {
                                    page++;
                                    load();
                                  })
                                : null,
                            icon: const Icon(Icons.chevron_right),
                          ),
                        ],
                      ),
                  ],
                );
              },
            ),
          ],
        ),
      ),
    ),
  );
}
