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

Y el sitio es, concretamente, un **tema hijo de Twenty Twenty-Five**:

```
stylesheet = fusem-2026        (hijo, autor del sitio; style.css sin Version)
template   = twentytwentyfive   (padre, vendor, se actualiza con WordPress)
CBT 2.10.2 activo · patrones: 9 archivos PHP en el hijo · wp_block en BD: 0
```

O sea que el «estado del tema» mezcla **tres dueños distintos**: el padre (vendor), el hijo
(código del sitio) y la BD (lo que se edita en el Site Editor). Tratarlos como uno solo es lo
que rompe el push.

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

**F2 — Autoridad por contenido y fecha (sin archivo de estado).**
Clasificar cada slug con la regla de §6 y aplicar la acción correspondiente, incluyendo resolver
las referencias (`wp:pattern`, `core/template-part`) antes de empujar.
*Aceptación:* (a) consolidar con CBT y empujar → el remoto queda con el archivo y sus referencias;
(b) editar en el Site Editor y empujar → queda la fila; (c) ambos casos dejan el remoto igual al
local sin intervención manual y sin archivos de estado.

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

## 5. Cómo funciona CBT de verdad (medido en este sitio)

Create Block Theme **no** crea una copia pasiva: al pulsar «Guardar cambios en el theme» hace,
en este orden:

1. Recorre las entidades FSE del **tema activo** (`wp_template`, `wp_template_part`,
   `wp_global_styles`; fuentes si se piden) filtrando por el namespace `wp_theme`.
2. Escribe cada una como archivo dentro del **stylesheet** (el hijo):
   `templates/<slug>.html`, `parts/<slug>.html`, y estilos en `theme.json` / `styles/*.json`.
3. **Borra las filas de la BD** de ese tema para que el archivo pase a mandar.

Es manual (un botón) y de una sola dirección (BD → archivos). No exporta `wp_block` y no
reescribe `patterns/*.php` (eso es código escrito a mano).

### Lo que hay realmente en `sillasenmovimiento.test`

```
#7   wp_template      front-page      tema=twentytwentyfive   mod 2026-10-25 21:56
#28  wp_template      page            tema=twentytwentyfive   mod 2026-10-25 21:56
#243 wp_template      single          tema=twentytwentyfive   mod 2026-10-25 21:56
#439 wp_template_part ambitos         tema=twentytwentyfive
#284 wp_template_part cabecera-fusem  tema=twentytwentyfive
#434 wp_template_part campus          tema=twentytwentyfive
#242 wp_template_part header          tema=twentytwentyfive

términos wp_theme: fusem-2026 (1) · twentytwentyfive (8)
home: NINGUNA fila en BD — sólo templates/home.html
templates/*.html escritos hoy 21:49–21:58 (la consolidación del usuario)
```

**Tres consecuencias que cambian el diseño:**

- **El bug tiene causa exacta**: `home` se consolidó y su fila desapareció, así que el botón del
  editor —que envía la fila— no tenía qué enviar. No es que «se exportara mal»: no había nada que
  exportar.
- **El overlay está namespaced por tema.** Las siete filas vivas pertenecen a
  `twentytwentyfive`, no a `fusem-2026`. Al pasar a un tema hijo, el overlay viejo queda huérfano
  (existe, pero ya no se usa). Un push que no discrimine por `wp_theme` empuja basura.
- **Una plantilla puede ser sólo una referencia**: `templates/home.html` contiene
  `<!-- wp:pattern {"slug":"fusem-2026/home"} /-->`. Empujar ese archivo sin su patrón no cambia
  nada. Hay que resolver las referencias `core/pattern` y `core/template-part` antes de empujar.

## 6. Regla de autoridad, sin archivo nuevo

El manifiesto que propuse en la versión anterior **sobra**: el estado ya se describe a sí mismo con
contenido + fecha. Y un archivo de estado que se puede desincronizar es justo lo que hay que evitar.

| Situación (mismo slug) | Quién manda | Qué hace el push |
|---|---|---|
| Sólo fila (no hay archivo) | **la fila** | empuja la fila y, si se puede, materializa el archivo |
| Sólo archivo (fila borrada por CBT) | **el archivo** | empuja el archivo **y** las referencias que use (`wp:pattern`, `core/template-part`) |
| Ambos, contenido idéntico | da igual | empuja la fila; no toca archivos |
| Ambos, contenido distinto | el **más nuevo** por fecha | si gana el archivo → aviso explícito «adoptar archivo»; si gana la fila → sobrescribe el archivo |

Regla mnemotécnica: **contenido primero, fecha después**. Nunca hace falta recordar en qué estado
quedó cada slug, porque se puede recalcular en el momento.

Y esto responde a la intuición original: **las personalizaciones viven en la BD y pisan el PHP del
tema** — eso es lo normal y así debe seguir. El archivo sólo manda cuando la BD ya no tiene nada
(consolidación), que es exactamente el caso de CBT.

## 6-bis. Brechas añadidas

| ID | Brecha |
|---|---|
| **G10** | No se detecta el estado «consolidado» (archivo sin fila): el push del editor no encuentra nada y no avisa |
| **G11** | El push no discrimina por `wp_theme`: puede empujar el overlay de un tema que ya no está activo |
| **G12** | No se resuelven las referencias de una plantilla (`core/pattern`, `core/template-part`): empujar el archivo sin sus piezas no cambia nada |
| **G13** | No se registra la versión del tema padre; una actualización de TT5 puede dejar el overlay huérfano sin aviso |

## 7. Preguntas abiertas

1. ~~¿Manifiesto de consolidación?~~ **Descartado**: se resuelve con contenido + fecha (§6). Si
   más adelante aparece un caso que no se pueda decidir así, se revisa.
2. Con CBT, ¿consolidar debe ser **paso obligatorio antes del push de un tema** (te obliga a
   materializar) o **opcional** (el push funciona igual, consolidás cuando querés versionar)?
3. ¿El push de tema incluye las filas `wp_block` (patrones creados desde el editor)? Son diseño y
   viven en BD, pero un cliente puede haberlos hecho en producción y perderías los suyos.
4. ¿Qué hacemos cuando cambia la versión del padre (`twentytwentyfive`) entre local y remoto?
   ¿Bloquear el push, avisar, o seguir?
5. ¿Push parcial por plantilla o siempre tema completo? (sigue abierta)
6. Rollback: ¿snapshot de filas + archivos por push, o backup de BD? (sigue abierta)

## 8. Notas técnicas para cuando se implemente

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

## 9. Cómo verificar a mano hoy (mientras no exista `theme-state`)

```bash
cd /c/laragon/www/sillasenmovimiento
wp eval 'foreach (["wp_template","wp_template_part","wp_global_styles","wp_navigation"] as $t)
  printf("%-18s %d\n", $t, wp_count_posts($t)->publish);'
ls -la wp-content/themes/fusem-2026/{templates,parts,patterns}
wp @sillasenmovimiento vk7k-sync status | grep -E "plugin_version|role"
```
