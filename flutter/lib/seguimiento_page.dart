import 'dart:async';

import 'package:flutter/material.dart';

import 'carta_api.dart';
import 'palette.dart';

class SeguimientoPage extends StatefulWidget {
  const SeguimientoPage({
    super.key,
    required this.slug,
    required this.token,
    required this.initial,
  });
  final String slug, token;
  final Map<String, dynamic> initial;
  @override
  State<SeguimientoPage> createState() => _SeguimientoPageState();
}

class _SeguimientoPageState extends State<SeguimientoPage>
    with WidgetsBindingObserver {
  final api = CartaApi();
  late Map<String, dynamic> order;
  Timer? timer;
  bool loading = false, foreground = true;
  String? error;
  @override
  void initState() {
    super.initState();
    order = widget.initial;
    WidgetsBinding.instance.addObserver(this);
    timer = Timer.periodic(const Duration(seconds: 15), (_) {
      if (foreground) refresh();
    });
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    foreground = state == AppLifecycleState.resumed;
    if (foreground) refresh();
  }

  Future<void> refresh() async {
    if (loading) return;
    loading = true;
    try {
      final data = await api.request(
        'carta/${widget.slug}/seguimiento?token=${Uri.encodeComponent(widget.token)}',
      );
      if (mounted) {
        setState(() {
          order = data;
          error = null;
        });
      }
    } catch (_) {
      if (mounted) {
        setState(
          () => error =
              'Sin actualización. El estado mostrado puede haber cambiado.',
        );
      }
    } finally {
      loading = false;
    }
  }

  @override
  void dispose() {
    timer?.cancel();
    WidgetsBinding.instance.removeObserver(this);
    api.close();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => Scaffold(
    appBar: AppBar(title: const Text('Tu pedido')),
    body: Center(
      child: Padding(
        padding: const EdgeInsets.all(24),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            const Icon(Icons.receipt_long, size: 64, color: ink),
            const SizedBox(height: 20),
            Text(
              'Pedido #${order['pedido_id']}',
              style: const TextStyle(fontSize: 28, fontWeight: FontWeight.bold),
            ),
            Text(
              {
                    'pendiente': 'Recibido · esperando aceptación',
                    'confirmado': 'Aceptado por el restaurante',
                    'preparando': 'En preparación',
                    'listo': 'Listo para entregar',
                    'entregado': 'Entregado',
                    'cancelado': 'Cancelado',
                  }[order['estado']] ??
                  order['estado'],
              style: const TextStyle(fontSize: 20),
            ),
            const SizedBox(height: 16),
            Text('Total registrado: S/ ${order['total']}'),
            const Text('El estado de preparación no confirma el pago.'),
            if (error != null) Text(error!),
            const SizedBox(height: 16),
            OutlinedButton(
              onPressed: refresh,
              child: const Text('Actualizar estado'),
            ),
            const Text(
              'Se consulta cada 15 segundos mientras esta pantalla está abierta.',
            ),
          ],
        ),
      ),
    ),
  );
}
