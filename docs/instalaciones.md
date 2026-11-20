# Instalaciones (una por cliente)

Una fila por instalación. **Sin claves, sin contraseñas, sin datos fiscales sensibles**: eso va en la ficha del cliente (Drive).
La renovación mensual de la licencia se controla con la columna «Licencia vence».

| Cliente (código) | Subdominio | Carpeta en el hosting | Rama | Versión | Modo ARCA | Licencia vence | Notas |
|---|---|---|---|---|---|---|---|
| OYS (`oys`) | facturador.oesteparts.site *(prueba)* | `public_html/facturador_web` | `main` (pasar a `estable`) | 0.7.1 | producción (PV 7) | no usa licencia | Alta 1. VFP en paralelo con agente. |

Desde 0.28.0 la versión, los cambios pendientes y la licencia de cada instalación se miran en vivo en **Sistema → Instalaciones** (esta tabla queda como registro de altas).
