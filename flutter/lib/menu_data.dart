class Dish {
  const Dish(
    this.id,
    this.name,
    this.category,
    this.description,
    this.cents,
    this.tag,
    this.ingredients,
    this.allergens, {
    this.modelAsset,
  });
  final String id, name, category, description, tag, ingredients, allergens;
  final int cents;
  final String? modelAsset;
}

String money(int cents) => 'S/ ${(cents / 100).toStringAsFixed(2)}';

const dishes = [
  Dish(
    'pizza',
    'Pizza de la casa',
    'Pizzas',
    'Masa dorada, mozzarella y pepperoni. Un clásico para compartir, recién salido del horno.',
    3200,
    'La favorita',
    'Trigo, tomate, mozzarella, pepperoni y orégano.',
    'Contiene trigo y leche.',
    modelAsset: 'assets/models/pizza.glb',
  ),
  Dish(
    'burger',
    'Burger de la casa',
    'Hamburguesas',
    'Carne a la parrilla, queso y vegetales frescos, con nuestra salsa especial.',
    2600,
    'A la parrilla',
    'Pan de trigo, res, queso, lechuga, tomate y salsa.',
    'Contiene trigo y leche. La salsa puede contener huevo.',
  ),
  Dish(
    'pasta',
    'Pasta al pesto',
    'Pastas',
    'Albahaca, tomates frescos y un toque de parmesano. Simple y llena de sabor.',
    2800,
    'Algo diferente',
    'Pasta de trigo, albahaca, aceite de oliva, frutos secos, tomate y parmesano.',
    'Contiene trigo, leche y frutos secos.',
  ),
  Dish(
    'salad',
    'Ensalada del huerto',
    'Ensaladas',
    'Vegetales de estación, hojas frescas y aderezo cítrico. Ligera y con mucho carácter.',
    2200,
    'Fresca y ligera',
    'Hojas verdes, vegetales de estación, limón y aceite de oliva.',
    'Consulta los ingredientes del día si tienes alguna alergia.',
  ),
];

const categories = ['Todo', 'Pizzas', 'Hamburguesas', 'Pastas', 'Ensaladas'];

String normalize(String value) {
  var result = value.toLowerCase().trim();
  const replacements = {
    'á': 'a',
    'é': 'e',
    'í': 'i',
    'ó': 'o',
    'ú': 'u',
    'ü': 'u',
  };
  replacements.forEach((from, to) => result = result.replaceAll(from, to));
  return result;
}
