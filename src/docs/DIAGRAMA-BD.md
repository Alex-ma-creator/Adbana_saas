# Diagrama de Base de Datos - Adbana SaaS (Entregable 25%)

Este documento contiene el modelo entidad-relación (ER) de la base de datos PostgreSQL de Adbana SaaS en formato **editable** (Mermaid y DBML).

---

## 1. Diagrama Entidad-Relación (Editable con Mermaid)

> Puedes editar este bloque directamente en Markdown, o copiarlo y pegarlo en [Mermaid Live Editor](https://mermaid.live).

```mermaid
erDiagram
    RESTAURANTES ||--o{ USERS : "tiene"
    RESTAURANTES ||--o{ CATEGORIAS : "organiza"
    RESTAURANTES ||--o{ PRODUCTOS : "posee"
    RESTAURANTES ||--o{ PEDIDOS : "recibe"
    RESTAURANTES ||--o{ CAJAS : "controla"
    RESTAURANTES ||--o{ MOVIMIENTOS_STOCK : "registra"
    RESTAURANTES ||--o{ GASTOS : "contabiliza"
    RESTAURANTES ||--o{ AUDITORIA : "audita"
    RESTAURANTES ||--o{ SOLICITUDES_PUBLICAS : "idempotencia"

    CATEGORIAS ||--o{ PRODUCTOS : "agrupa"

    USERS ||--o{ PEDIDOS : "atiende"
    USERS ||--o{ CAJAS : "opera"
    USERS ||--o{ MOVIMIENTOS_CAJA : "ejecuta"
    USERS ||--o{ MOVIMIENTOS_STOCK : "declara"
    USERS ||--o{ GASTOS : "rinde"
    USERS ||--o{ AUDITORIA : "genera"

    CAJAS ||--o{ MOVIMIENTOS_CAJA : "acumula"
    CAJAS ||--o{ PEDIDOS : "cobra"

    PEDIDOS ||--o{ DETALLE_PEDIDOS : "contiene"
    PEDIDOS ||--o{ MOVIMIENTOS_STOCK : "descuenta"
    PEDIDOS ||--o| SOLICITUDES_PUBLICAS : "asociada"

    PRODUCTOS ||--o{ DETALLE_PEDIDOS : "se_vende_en"
    PRODUCTOS ||--o{ MOVIMIENTOS_STOCK : "afectado_por"

    RESTAURANTES {
        bigint id PK
        string nombre
        string slug UK
        string telefono
        boolean activo
        timestamp created_at
        timestamp updated_at
    }

    USERS {
        bigint id PK
        bigint restaurante_id FK
        string name
        string email UK
        string password
        string rol
        json permisos_personalizados
        boolean activo
        timestamp created_at
        timestamp updated_at
    }

    CATEGORIAS {
        bigint id PK
        bigint restaurante_id FK
        string nombre
        integer orden
        boolean activo
        timestamp created_at
        timestamp updated_at
    }

    PRODUCTOS {
        bigint id PK
        bigint restaurante_id FK
        bigint categoria_id FK
        string nombre
        text descripcion
        decimal precio
        decimal costo
        integer stock
        integer stock_minimo
        string imagen
        boolean activo
        timestamp created_at
        timestamp updated_at
    }

    PEDIDOS {
        bigint id PK
        bigint restaurante_id FK
        bigint user_id FK
        bigint caja_id FK
        string cliente_nombre
        string cliente_telefono
        string tipo
        string mesa
        string direccion
        string estado
        string medio_pago
        decimal total
        decimal recibido
        decimal vuelto
        uuid clave_operacion UK
        timestamp cobrado_at
        text notas
        timestamp created_at
        timestamp updated_at
    }

    DETALLE_PEDIDOS {
        bigint id PK
        bigint pedido_id FK
        bigint producto_id FK
        string nombre_producto
        integer cantidad
        decimal precio_unitario
        decimal costo_unitario
        decimal subtotal
        timestamps created_at
    }

    CAJAS {
        bigint id PK
        bigint restaurante_id FK
        bigint user_id FK
        decimal saldo_inicial
        decimal efectivo_contado
        decimal efectivo_esperado
        decimal diferencia
        timestamp cerrada_at
        timestamp created_at
        timestamp updated_at
    }

    MOVIMIENTOS_CAJA {
        bigint id PK
        bigint caja_id FK
        string tipo
        decimal monto
        string motivo
        timestamp created_at
        timestamp updated_at
    }

    MOVIMIENTOS_STOCK {
        bigint id PK
        bigint restaurante_id FK
        bigint producto_id FK
        bigint user_id FK
        bigint pedido_id FK
        string tipo
        integer cantidad
        string motivo
        timestamp created_at
        timestamp updated_at
    }

    GASTOS {
        bigint id PK
        bigint restaurante_id FK
        bigint user_id FK
        string descripcion
        decimal monto
        string categoria
        timestamp created_at
        timestamp updated_at
    }

    AUDITORIA {
        bigint id PK
        bigint restaurante_id FK
        bigint user_id FK
        string tabla
        bigint registro_id
        string accion
        json datos_anteriores
        json datos_nuevos
        timestamp created_at
        timestamp updated_at
    }

    SOLICITUDES_PUBLICAS {
        bigint id PK
        bigint restaurante_id FK
        uuid clave UK
        char huella
        bigint pedido_id FK
        timestamp created_at
        timestamp updated_at
    }
```

---

## 2. Formato DBML (Database Markup Language)

> Puedes copiar este código en [dbdocs.io](https://dbdocs.io) o [dbdiagram.io](https://dbdiagram.io) para generar y exportar diagramas en PDF, PNG o SVG interactivos.

```dbml
Table restaurantes {
  id bigint [pk, increment]
  nombre varchar
  slug varchar [unique]
  telefono varchar
  activo boolean [default: true]
  created_at timestamp
  updated_at timestamp
}

Table users {
  id bigint [pk, increment]
  restaurante_id bigint [ref: > restaurantes.id]
  name varchar
  email varchar [unique]
  password varchar
  rol varchar [default: 'cajero']
  permisos_personalizados json
  activo boolean [default: true]
  created_at timestamp
  updated_at timestamp
}

Table categorias {
  id bigint [pk, increment]
  restaurante_id bigint [ref: > restaurantes.id]
  nombre varchar
  orden int [default: 0]
  activo boolean [default: true]
  created_at timestamp
  updated_at timestamp
}

Table productos {
  id bigint [pk, increment]
  restaurante_id bigint [ref: > restaurantes.id]
  categoria_id bigint [ref: > categorias.id]
  nombre varchar
  descripcion text
  precio decimal(10,2)
  costo decimal(10,2) [default: 0]
  stock int [default: 0]
  stock_minimo int [default: 0]
  imagen varchar
  activo boolean [default: true]
  created_at timestamp
  updated_at timestamp
}

Table pedidos {
  id bigint [pk, increment]
  restaurante_id bigint [ref: > restaurantes.id]
  user_id bigint [ref: > users.id]
  caja_id bigint [ref: > cajas.id]
  cliente_nombre varchar
  cliente_telefono varchar
  tipo varchar
  mesa varchar
  direccion varchar
  estado varchar
  medio_pago varchar
  total decimal(10,2)
  recibido decimal(12,2)
  vuelto decimal(12,2)
  clave_operacion uuid
  cobrado_at timestamp
  notas text
  created_at timestamp
  updated_at timestamp
}

Table detalle_pedidos {
  id bigint [pk, increment]
  pedido_id bigint [ref: > pedidos.id]
  producto_id bigint [ref: > productos.id]
  nombre_producto varchar
  cantidad int
  precio_unitario decimal(10,2)
  costo_unitario decimal(10,2)
  subtotal decimal(10,2)
  created_at timestamp
}

Table cajas {
  id bigint [pk, increment]
  restaurante_id bigint [ref: > restaurantes.id]
  user_id bigint [ref: > users.id]
  saldo_inicial decimal(12,2)
  efectivo_contado decimal(12,2)
  efectivo_esperado decimal(12,2)
  diferencia decimal(12,2)
  cerrada_at timestamp
  created_at timestamp
  updated_at timestamp
}

Table movimientos_caja {
  id bigint [pk, increment]
  caja_id bigint [ref: > cajas.id]
  tipo varchar(10)
  monto decimal(12,2)
  motivo varchar
  created_at timestamp
  updated_at timestamp
}

Table movimientos_stock {
  id bigint [pk, increment]
  restaurante_id bigint [ref: > restaurantes.id]
  producto_id bigint [ref: > productos.id]
  user_id bigint [ref: > users.id]
  pedido_id bigint [ref: > pedidos.id]
  tipo varchar(20)
  cantidad int
  motivo varchar
  created_at timestamp
  updated_at timestamp
}

Table gastos {
  id bigint [pk, increment]
  restaurante_id bigint [ref: > restaurantes.id]
  user_id bigint [ref: > users.id]
  descripcion varchar
  monto decimal(10,2)
  categoria varchar
  created_at timestamp
  updated_at timestamp
}

Table auditoria {
  id bigint [pk, increment]
  restaurante_id bigint [ref: > restaurantes.id]
  user_id bigint [ref: > users.id]
  tabla varchar
  registro_id bigint
  accion varchar
  datos_anteriores json
  datos_nuevos json
  created_at timestamp
  updated_at timestamp
}

Table solicitudes_publicas {
  id bigint [pk, increment]
  restaurante_id bigint [ref: > restaurantes.id]
  clave uuid
  huella char(64)
  pedido_id bigint [ref: > pedidos.id]
  created_at timestamp
  updated_at timestamp
}
```
