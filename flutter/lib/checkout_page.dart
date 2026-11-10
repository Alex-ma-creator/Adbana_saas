import 'package:flutter/material.dart';

import 'carta_api.dart';
import 'seguimiento_page.dart';

class CheckoutPage extends StatefulWidget {
  const CheckoutPage({
    super.key,
    required this.slug,
    required this.restaurant,
    required this.items,
    required this.total,
  });
  final String slug;
  final Map<String, dynamic> restaurant;
  final List<Map<String, int>> items;
  final int total;
  @override
  State<CheckoutPage> createState() => _CheckoutPageState();
}

class _CheckoutPageState extends State<CheckoutPage> {
  final api = CartaApi();
  final form = GlobalKey<FormState>();
  final name = TextEditingController(),
      phone = TextEditingController(),
      address = TextEditingController(),
      table = TextEditingController();
  late String mode;
  bool sending = false;
  String? error;
  Map<String, dynamic>? pending;
  List<String> get modes => List<String>.from(
    widget.restaurant['configuracion']?['modalidades'] ??
        ['mesa', 'recojo', 'delivery'],
  );
  @override
  void initState() {
    super.initState();
    mode = modes.contains('recojo') ? 'recojo' : modes.first;
  }

  @override
  void dispose() {
    api.close();
    name.dispose();
    phone.dispose();
    address.dispose();
    table.dispose();
    super.dispose();
  }

  Future<void> send() async {
    if (sending || (pending == null && !form.currentState!.validate())) return;
    setState(() {
      sending = true;
      error = null;
    });
    pending ??= {
      'clave_operacion': newOperationKey(),
      'cliente_nombre': name.text.trim(),
      'cliente_telefono': phone.text.trim(),
      'tipo': mode,
      'mesa': mode == 'mesa' ? table.text.trim() : null,
      'direccion': mode == 'delivery' ? address.text.trim() : null,
      'items': widget.items,
    };
    try {
      final data = await api.request(
        'carta/${widget.slug}/pedidos',
        body: pending,
      );
      if (!mounted) return;
      await Navigator.push(
        context,
        MaterialPageRoute<void>(
          builder: (_) => SeguimientoPage(
            slug: widget.slug,
            token: data['seguimiento_token'],
            initial: data,
          ),
        ),
      );
      if (mounted) Navigator.pop(context, true);
    } catch (e) {
      if (mounted) {
        setState(() {
          if (e is CartaApiException && e.status == 422) {
            pending = null;
            error = e.message;
          } else {
            error = 'No pudimos confirmar el resultado. Reintenta sin cambiar los datos: se usará la misma solicitud para evitar duplicados.';
          }
        });
      }
    } finally {
      if (mounted) setState(() => sending = false);
    }
  }

  @override
  Widget build(BuildContext context) => PopScope(
    canPop: !sending && pending == null,
    child: Scaffold(
      appBar: AppBar(title: Text(widget.restaurant['nombre'])),
      body: Center(
        child: ConstrainedBox(
          constraints: const BoxConstraints(maxWidth: 600),
          child: Form(
            key: form,
            child: ListView(
              padding: const EdgeInsets.all(24),
              children: [
                const Text(
                  'Confirma tu pedido',
                  style: TextStyle(fontSize: 26, fontWeight: FontWeight.bold),
                ),
                const Text(
                  'El restaurante debe aceptar tu solicitud. No se realiza ningún cargo automático.',
                ),
                const SizedBox(height: 20),
                DropdownButtonFormField<String>(
                  initialValue: mode,
                  items: [
                    for (final m in modes)
                      DropdownMenuItem(
                        value: m,
                        child: Text(
                          {
                            'mesa': 'En mesa',
                            'recojo': 'Para recoger',
                            'delivery': 'Delivery del restaurante',
                          }[m]!,
                        ),
                      ),
                  ],
                  onChanged: sending || pending != null
                      ? null
                      : (v) => setState(() => mode = v!),
                  decoration: const InputDecoration(
                    labelText: 'Cómo recibirlo',
                  ),
                ),
                const SizedBox(height: 16),
                field(name, 'Tu nombre'),
                field(phone, 'Teléfono', required: mode == 'delivery'),
                if (mode == 'mesa')
                  field(table, 'Nombre de mesa (confírmalo con tu mesero)'),
                if (mode == 'delivery') field(address, 'Dirección de entrega'),
                if (mode == 'delivery')
                  const Text(
                    'El restaurante confirmará cobertura y costo de entrega. El total mostrado corresponde a los productos.',
                  ),
                Text(
                  'Productos: S/ ${(widget.total / 100).toStringAsFixed(2)}',
                  style: const TextStyle(
                    fontSize: 20,
                    fontWeight: FontWeight.bold,
                  ),
                ),
                if (error != null)
                  Padding(
                    padding: const EdgeInsets.symmetric(vertical: 16),
                    child: Text(
                      error!,
                      style: const TextStyle(color: Colors.red),
                    ),
                  ),
                const SizedBox(height: 20),
                FilledButton(
                  onPressed: sending ? null : send,
                  child: sending
                      ? const SizedBox(
                          width: 22,
                          height: 22,
                          child: CircularProgressIndicator(strokeWidth: 2),
                        )
                      : Text(
                          pending == null
                              ? 'Enviar al restaurante'
                              : 'Consultar mediante reintento seguro',
                        ),
                ),
              ],
            ),
          ),
        ),
      ),
    ),
  );
  Widget field(
    TextEditingController controller,
    String label, {
    bool required = true,
  }) => Padding(
    padding: const EdgeInsets.symmetric(vertical: 10),
    child: TextFormField(
      controller: controller,
      readOnly: sending || pending != null,
      maxLength: label.startsWith('Dirección')
          ? 255
          : label.startsWith('Nombre de mesa')
          ? 20
          : label == 'Teléfono'
          ? 30
          : 120,
      decoration: InputDecoration(labelText: label),
      validator: (v) =>
          required && (v ?? '').trim().isEmpty ? 'Completa este dato' : null,
    ),
  );
}
