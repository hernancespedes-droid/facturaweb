# Cómo llega un cambio a todos los clientes

**Un solo código para todos.** Lo que cambia de un cliente a otro está en dos lugares y nada más: su `config/local.php` (base, mail, ARCA, licencia, Mercado Pago, perfil) y los **Parámetros / Configuración** de su propia base. Nunca hay ramas ni copias por cliente.

## Las dos ramas
| Rama | Qué es | Quién la sigue |
|---|---|---|
| `main` | Lo último que se desarrolla. | La instalación de **prueba** de Hernán (y Dev Senior al probar). |
| `estable` | Lo que ya se probó y se **publicó**. | **Todos los clientes** (su Ferozo → GIT apunta a `estable`). |

## Publicar una versión (lo único que hace Hernán)
1. Se prueba en la instalación de prueba (la que sigue `main`).
2. Hernán le dice a Dev Senior **«publicá»** (o «publicá la 0.28.0»). Dev Senior mueve `estable` a lo que hay en `main` y le pone la etiqueta de la versión (`v0.28.0`).
3. El webhook de auto-despliegue de cada cliente (GitHub → Ferozo) baja el código nuevo solo.
4. En cada cliente, **el primer pedido después de actualizar aplica solo los cambios de base pendientes** (`src/Core/AutoMigracion.php`: unos segundos, con candado; nadie entra a «Aplicar cambios»).
5. Hernán mira **Sistema → Instalaciones** → «Revisar todas»: versión, cambios pendientes y días de licencia de cada cliente. Una instalación desactualizada o con la actualización fallida sale en rojo.

## Volver atrás
Si una versión publicada trae un problema: «Dev Senior, volvé `estable` a la v0.27.0». Las migraciones son siempre aditivas, así que el código anterior sigue funcionando con la base ya actualizada.

## Reglas para que esto sea seguro
- **Migraciones solo aditivas e idempotentes** (columnas y tablas nuevas, permisos; nunca borrar ni renombrar): están en `CLAUDE.md`. Por eso se pueden aplicar solas.
- **No modificar los scripts congelados de `bin/`** (Ferozo les cambia los permisos y GIT dejaría de sincronizar). Todo lo nuevo, en archivos nuevos.
- Una instalación **nueva** nace siguiendo `estable` (`bin/alta-cliente.php` lo pone en PASOS).
- Si la actualización automática falla en un cliente, el sistema **no se bloquea**: anota el error (Sistema lo muestra al Master y `/salud` lo informa) y no insiste durante un minuto. Se arregla y se aprieta «Aplicar cambios».

## Cambiar la instalación que ya existe (una sola vez)
Para OYS (y cualquier cliente que hoy siga `main`): Ferozo → GIT → editar el repositorio de la instalación → rama **`estable`**, y activar el webhook de auto-despliegue (cargar la URL en GitHub → Settings → Webhooks, evento *push*). La primera vez conviene hacerlo en una instalación de prueba o poco crítica. Después, la instalación de prueba de Hernán sigue `main`.
