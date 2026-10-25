# Roadmap — Push de temas (FSE) sin dejar el estado a medias

> Estado: **documento de diseño, nada implementado**. Versión del plugin al escribir esto: 1.9.2.
> Caso que lo dispara: editar la plantilla `home` en `sillasenmovimiento.test`, pulsar
> «Guardar cambios en el theme» (Create Block Theme) y hacer push desde el editor → el cambio
> no aparece en el remoto.

## 1. Por qué pasa: el estado de un tema son SIETE capas

| # | Capa | Dónde vive | Quién la escribe | ¿La empuja wp-sync hoy? |
|---|---|---|---|---|
| 1 | Archivos del tema (`templates/*.html`, `parts/*.html`, `patterns/*.php`, `theme.json`, `styles/*.json`, `assets/`) | disco | Create Block Theme / a mano / plugin | Sí, con `components=themes` — pero es un **paso separado** |
| 2 | Plantillas y partes en BD (`wp_template`, `wp_template_part`) | BD (`wp_posts`) | Site Editor | Sí, en el **bundle FSE** — **no** desde el botón del editor |
| 3 | Estilos globales (`wp_global_styles` + postmeta `theme_json`) | BD | Site Editor / CBT | Sí, en el bundle FSE |
| 4 | Navegación (`wp_navigation` + `nav_menu_item`) | BD | Site Editor | Sí, en el bundle FSE |
| 5 | Patrones sincronizados (`wp_block`) | BD | editor | Sí, en el bundle FSE |
| 6 | `theme_mods_<slug>` | `wp_options` | Personalizador | **Solo** al cambiar de tema (`themes/switch`) |
| 7 | Fuentes (`wp_font_family`, `wp_font_face` + `assets/fonts`) | BD + disco | CBT | BD sí (bundle); archivos solo si van en `themes` |

**La regla del core que explica el síntoma:** si existe una fila en `wp_template` con ese slug,
**gana sobre el archivo**. Y al revés: si cambia el archivo pero la fila de la BD no, el sitio
sigue sirviendo la fila. Es decir, **el push tiene que mover las dos capas en el mismo acto**; si
mueve una sola, el resultado visible no cambia o queda incoherente.

> Ya está anotado en el propio código (`class-vk7k-sync-api.php:476`):
> *"A wp_template(_part) row alone is not enough: WP keeps serving the theme file"*.

## 2. Qué hace hoy wp-sync (tres caminos distintos)

| Camino | Qué envía | Qué le falta |
|---|---|---|
| **Wizard / CLI** (`db` → `fse` → `files`) | volcado de BD, luego `export_fse_bundle()` (los 7 tipos FSE + postmeta + términos), luego archivos por componentes | los pasos son independientes: se pueden correr en cualquier orden y ninguno exige que el otro haya corrido |
| **Botón del editor** (`vk7k_sync_editor_push`) | **solo el post** (`post_id` + `post_type`) vía export granular | archivo del tema, partes referenciadas, estilos globales, navegación, `theme_mods` |
| **`push_theme` / `push_plugin`** | solo archivos (+ activación) | filas FSE, estilos, mods |

Estado real medido en `sillasenmovimiento.test` al escribir esto:

```
BD : wp_template 3 · wp_template_part 4 · wp_global_styles 2 · wp_navigation 2 · theme_mods 3
disco: templates 5 · parts 4 · patterns 9 · theme.json sí
```

O sea: cinco plantillas en disco y tres filas en BD — **la mezcla exacta** que hace que un push
parcial produzca un remoto distinto al local.

## 3. Brechas (la lista)

| ID | Brecha |
|---|---|
| **G1** | El push del editor no incluye el **archivo** de la plantilla |
| **G2** | El push del editor no incluye `wp_template_part` referenciadas (header/footer), `wp_global_styles` ni `wp_navigation` |
| **G3** | No hay orden garantizado ni verificación «archivo + fila»: se puede empujar una y no la otra |
| **G4** | No se detecta que CBT exportó (archivos más nuevos que la BD) → se empuja lo viejo |
| **G5** | `wp_global_styles` no se exporta a `theme.json`/`styles/` al empujar |
| **G6** | `theme_mods` solo viaja al cambiar de tema, nunca con el push normal |
| **G7** | Sin verificación posterior (hash del archivo remoto, `post_modified` de la fila) |
| **G8** | Sin diff previo: no se puede ver qué se va a cambiar antes de empujar |
| **G9** | No existe «push de tema» atómico (archivos + BD + mods + fuentes) ni rollback conjunto |

## 4. Fases propuestas

**F0 — Diagnóstico (sin escribir nada).**
`wp vk7k-sync theme-state [--remote] [--json]`: lista las 7 capas del local y del remoto con
fecha/hash/slug. Sirve para responder «¿qué está desalineado?» antes de tocar.
*Aceptación:* comparativa legible de local vs remoto para un tema dado.

**F1 — El botón del editor, completo.**
Al empujar desde FSE un `wp_template` o `wp_template_part`: adjuntar automáticamente el archivo
homónimo del tema (si existe), las partes que referencia, y avisar si `wp_global_styles` o
`wp_navigation` están desactualizados.
*Aceptación:* editar `home`, push → el remoto sirve el mismo HTML (hash de fila + hash de archivo).

**F2 — Coherencia archivo ↔ fila (la decisión de fondo).**
Declarar la fuente de verdad y hacerla explícita en el push: si manda la BD, se empuja la fila y
se actualiza el archivo; si manda el archivo (CBT), se empuja el archivo y se **elimina/invalida
la fila remota**. Nunca las dos contradictorias.
*Aceptación:* tras el push, `wp_template` remoto y `templates/*.html` remoto coinciden (o sólo
existe uno de los dos, por diseño).

**F3 — `push-theme` atómico.**
Un comando único: archivos + bundle FSE + `theme_mods` + fuentes, en el orden correcto
(fila → archivo), con verificación final y snapshot para volver atrás.
*Aceptación:* `wp vk7k-sync push-theme fusem-2026` deja el remoto byte a byte igual al local en
las 7 capas, y lo reporta.

**F4 — Dry-run y reporte.**
Diff previo (archivos nuevos/modificados/borrados + filas + estilos), en JSON para la UI y en
texto para CLI. Integrado al modal del editor antes de confirmar (G8).
*Aceptación:* el push se puede previsualizar y el usuario ve exactamente qué cambia.

**F5 — Integración con Create Block Theme.**
Detectar exportaciones pendientes («el tema en disco está más nuevo que la BD») y ofrecer
«exportar todo el tema» antes del push (G4).
*Aceptación:* editar, guardar en el theme y empujar funciona en un solo gesto.

## 5. Preguntas abiertas (necesito tu decisión para F2/F3)

1. **¿Cuál es la fuente de verdad: archivo (CBT) o BD (Site Editor)?** Hoy gana el que exista, y
   eso es la raíz del problema. Mi recomendación: **archivo**, con la BD como caché que se limpia
   al empujar — es lo único que sobrevive a clones, migraciones y control de versiones.
2. Cuando manda el archivo, ¿el push **borra** la fila remota o la reescribe con el contenido del
   archivo? (borrar es más limpio; reescribir evita huecos si algo falla)
3. ¿Push parcial por plantilla o siempre **tema completo**? Lo segundo es más seguro; lo primero,
   más rápido en un ajuste chico.
4. Los **patrones** (`patterns/*.php`) viajan con el tema, ¿o se gestionan aparte porque los edita
   el cliente?
5. `wp_global_styles` del remoto: ¿se pisan siempre, o se respeta lo que tenga el sitio?
6. Rollback: ¿snapshot por push (archivos + filas) o confiar en el backup de BD?

## 6. Notas técnicas para cuando se implemente

- `wp_template.post_name` = slug del archivo (`home`, `page`…); la taxonomía `wp_theme` lleva el
  namespace del tema (`fusem-2026`). El archivo vive en `themes/<tema>/templates/<slug>.html`.
- `wp_template_part` lleva además la taxonomía `wp_template_part_area` (header/footer/sidebar).
- `handle_files_write_theme_template()` ya existe y **escribe el archivo** en el remoto: hoy sólo se
  usa al hacer pull. El equivalente conceptual para push es lo que falta (G1).
- El API remoto ya resuelve conflictos de ID por `post_type + post_name` (`class-vk7k-sync-api.php:909`),
  así que no hace falta replicar IDs.
- `export_fse_bundle()` (db.php:1385) es la pieza que ya exporta los 7 tipos + postmeta + términos:
  reutilizarla, no reescribirla.
- CBT escribe `templates/`, `parts/`, `patterns/`, `styles/`, `theme.json` y, según la opción
  elegida, borra las filas de BD para que el tema mande. Esa opción es la que define F2.

## 7. Cómo verificar a mano hoy (mientras no exista `theme-state`)

```bash
cd /c/laragon/www/sillasenmovimiento
wp eval 'foreach (["wp_template","wp_template_part","wp_global_styles","wp_navigation"] as $t)
  printf("%-18s %d\n", $t, wp_count_posts($t)->publish);'
ls -la wp-content/themes/fusem-2026/{templates,parts,patterns}
wp @sillasenmovimiento vk7k-sync status | grep -E "plugin_version|role"
```
