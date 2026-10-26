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
`wp vk7k-sync theme-state [--remote] [--json]`: las 7 capas del local y del remoto, por slug, con
contenido/fecha. *Aceptación:* responde «¿qué está desalineado?» sin tocar nada.

**F1 — Preflight: contrato de identidad de tema (bloqueante).**
Antes de cualquier push de tema, verificar y —si falta— resolver (§5-bis). Nada se envía si la
identidad no coincide o si la familia no es transferible.
*Aceptación:* push con remoto en otro tema → se bloquea con un mensaje que dice qué hacer; push con
el mismo tema → pasa sin fricción.

**F2 — Autoridad por contenido y fecha + referencias.**
Clasificar cada slug con §6 y resolver `core/pattern` / `core/template-part` antes de empujar.
*Aceptación:* (a) consolidado con CBT + push → el remoto queda con archivo y patrón; (b) editado en
Site Editor + push → queda la fila; (c) nunca queda un remoto a medias.

**F3 — `push-theme` atómico.**
Archivos + overlay + `theme_mods` + fuentes en el orden seguro de §5-bis, con verificación final y
snapshot para revertir. *Aceptación:* el remoto iguala al local en todas las capas y lo reporta.

**F4 — Dry-run y reporte.**
Diff previo en JSON (archivos + filas + mods) para el modal del editor. *Aceptación:* se ve qué
cambia antes de confirmar.

**F5 — Integración con Create Block Theme.**
Detectar exportación pendiente («el disco está más nuevo que la BD») y ofrecer consolidar antes del
push. *Aceptación:* editar → consolidar → empujar, en un gesto.

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

## 6-ter. Familias de overlay: no todas son lo mismo

Respondiendo a la duda: **no**, el overlay de un tema de bloques no es el mismo mecanismo que las
personalizaciones clásicas. Hay tres familias y un conjunto de cosas que no son «del tema»:

| Familia | De dónde sale | Dónde vive | Se puede empujar como dato |
|---|---|---|---|
| **FSE (bloques)** | Site Editor | `wp_template`, `wp_template_part`, `wp_global_styles` (+postmeta `theme_json`), `wp_navigation` | **Sí**: registros estructurados, namespaced por `wp_theme` |
| **Clásico (p. ej. Storefront)** | Personalizador | `theme_mods_<slug>` (options), `custom_css` (post), `sidebars_widgets` + `widget_*` | **Depende del código del tema**: son claves arbitrarias que interpreta su PHP |
| **Constructor** (Elementor y similares) | el propio builder | postmeta + CSS en uploads + tablas propias | Modelo propio, ajeno a WP core |

Y cosas que **no** son del tema aunque la gente las llame así: menús (`nav_menu`), páginas,
`show_on_front` / `page_on_front`, y los medios. Son del **sitio** y viajan aparte.

**Consecuencias:**

- CBT sólo tiene sentido en la familia FSE: su trabajo es materializar esas filas en archivos. Con
  Storefront (clásico) no aplica, porque no hay `templates/` que exportar.
- El overlay de CBT **es** el overlay FSE, visto en otro soporte. No es un cuarto mecanismo.
- Un `wp_template` empujado a un sitio que corre otro tema se guarda con el namespace equivocado:
  **no se rompe nada, pero no hace nada** — el peor resultado posible, porque parece éxito.
- Un `theme_mods_storefront` en un sitio con otro tema tampoco hace nada, y si el tema es la misma
  familia pero **otra versión**, las claves pueden haber cambiado de significado.

Por eso el push no puede ser «copiar la capa 2»: tiene que saber **de qué familia** es el tema
(`wp_is_block_theme()`), qué claves son suyas y cuáles son del sitio.

## 6-quater. Preflight obligatorio (lo que pediste)

Contrato: **no se empuja un tema cuya identidad no coincide con la del remoto.**

| # | Chequeo | Si falla |
|---|---|---|
| P1 | `stylesheet` local == remoto | ofrecer instalar el tema y activarlo (con confirmación explícita) |
| P2 | `template` (padre) local == remoto, incluido el caso child | ídem: instalar el padre, luego el hijo |
| P3 | Misma **familia** (bloque vs clásico) | **abortar**: el overlay no es transferible |
| P4 | Versión del padre registrada == remoto | avisar y permitir abortar (una actualización puede invalidar el overlay) |
| P5 | El remoto no tiene overlay propio para ese tema, o el usuario decide qué hacer | ofrecer **sobrescribir / conservar / abortar** (esto evita perder customizaciones hechas en producción) |
| P6 | Si el tema usa portada estática, la página de `page_on_front` existe en remoto | avisar: activar sin ella deja la portada vacía |

**Orden seguro de ejecución** (importa, y es contraintuitivo):

```
1. instalar/copiar archivos del tema (padre y hijo)      ← sin activar
2. empujar el overlay (filas FSE + theme_mods + css)     ← aún sin activar
3. activar el tema en remoto                             ← el sitio cambia ya con su overlay puesto
4. verificar: front 200, tema activo, nº de plantillas
5. si algo falla: restaurar tema anterior + snapshot
```

Activar **antes** de empujar el overlay deja el sitio unos segundos con el aspecto por defecto. Al
revés, el cambio de tema ya aparece vestido.

**Lo que ya existe y se reutiliza:** `/themes/list` (temas disponibles en remoto), `/themes/switch`
(activa + aplica `theme_mods`, y devuelve `is_child`/`parent_theme`), `push_theme()` (sube
archivos del tema). Falta la política: hoy `/themes/switch` falla si el tema no existe en remoto y
nadie comprueba identidad ni familia.

## 6-quinquies. Brechas del preflight

| ID | Brecha |
|---|---|
| **G14** | No hay preflight: se puede empujar un tema sobre un sitio que corre otro, y el resultado es un éxito falso |
| **G15** | No hay flujo de instalación de tema remoto (el switch exige que ya exista) |
| **G16** | No se detecta la familia del tema ni se ajusta qué overlay corresponde |
| **G17** | No hay política para el overlay que ya existe en el remoto (sobrescribir vs conservar) |
| **G18** | No se verifica la portada estática (`page_on_front`) antes de activar |

## 7. Preguntas abiertas

1. ~~¿Manifiesto de consolidación?~~ **Descartado**: se resuelve con contenido + fecha (§6).
2. **¿El preflight es bloqueante duro o advertible?** Mi recomendación: duro para P3 (familia) y P1/P2
   sin remedio; advertible con confirmación para P4/P5/P6.
3. **Si el remoto ya tiene customizaciones de ese tema, ¿qué gana por defecto?** Mi recomendación:
   preguntar siempre, sin default silencioso; y guardar snapshot del overlay remoto antes de pisar.
4. Con CBT, ¿consolidar es paso obligatorio antes del push o el push funciona igual sin consolidar?
5. ¿Push de tema completo o por plantilla? Mi recomendación: **completo**, porque las referencias
   (`core/pattern`, `core/template-part`) hacen que una plantilla nunca esté sola.
6. ¿Qué hacemos cuando cambia la versión del padre entre local y remoto: bloquear, avisar o seguir?
7. Rollback: ¿snapshot de filas + archivos por push, o backup de BD?

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
