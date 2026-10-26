# Plan detallado — F1: Preflight de identidad de tema (bloqueante)

> Estado: **plan para aprobar. Nada implementado.**
> Alcance: F1 del `ROADMAP-TEMAS.md`. No incluye F2 (autoridad por contenido/fecha) ni F3
> (`push-theme` atómico); F1 sólo **verifica y decide**, no cambia la forma de empujar.
> Decisiones ya tomadas: preflight **duro** para familia e identidad · **preguntar siempre** ante
> overlay ajeno · **push de tema completo** (nunca por plantilla).

---

## 0-bis. Principio: el push de tema es **unidireccional**

El flujo de tema sólo va **local → remoto**. Dentro del push **no hay ninguna acción de traer**,
ni completa ni parcial: si el remoto tiene trabajo que importa, se detiene el push y se hace un
**pull completo con wp-sync** (que sobrescribe el local), y recién después se vuelve a empujar.
Así el flujo queda ordenado y no hay dos verdades circulando a la vez.

Consecuencia en el diseño: ningún chequeo ofrece «traer» como remedio. Como mucho, el mensaje
**dice** que corresponde hacerlo, fuera de este flujo.

---

## 0. Qué hace y qué no hace F1

| Hace | No hace |
|---|---|
| Compara la identidad del tema local con la del remoto | No cambia qué se empuja ni cómo |
| Detecta familia (bloque/clásico), versión del padre y overlay ajeno | No implementa la regla contenido+fecha (F2) |
| Bloquea el push y ofrece **acciones de remediación** | No instala nada sin confirmación explícita |
| Deja un registro de la decisión (auditoría) | No toca el contenido del sitio |

---

## 1. Piezas que se agregan

### 1.1 Estado de tema, calculado (sin archivos nuevos, sin tablas)

`VK7K_Sync_Theme_State::local( $stylesheet = null )` y `::remote()` devuelven la misma estructura:

```json
{
  "stylesheet": "fusem-2026",
  "template": "twentytwentyfive",
  "is_child": true,
  "is_block_theme": true,
  "versions": { "stylesheet": "", "template": "1.0" },
  "overlay": {
    "templates": { "count": 0, "max_modified": null },
    "parts":     { "count": 0, "max_modified": null },
    "global_styles": { "count": 2, "hash": "a1b2..." },
    "navigation":    { "count": 2, "hash": "c3d4..." },
    "patterns_db":   { "count": 0 }
  },
  "theme_mods_keys": ["custom_logo", "..."],
  "custom_css_hash": "e5f6...",
  "page_on_front": 0
}
```

- **Todo derivado**: se recalcula en cada llamada. No hay `.json` de estado ni tabla nueva
  (fue una decisión explícita: nada que se pueda desincronizar).
- El **overlay se cuenta y se hashea por tema** (`wp_theme` term), no globalmente: en este sitio
  las 7 filas vivas son de `twentytwentyfive`, y contarlas como «del sitio» fue lo que confundió
  el diagnóstico.

### 1.2 Endpoint remoto nuevo

`GET /wp-json/vk7k-sync/v1/theme/state` → devuelve la estructura de §1.1 del remoto.
Firma HMAC igual que el resto. Sólo lectura, capability `manage_options`.

**Degradación obligatoria:** si el remoto no tiene este endpoint (plugin viejo), el preflight
**bloquea** con «actualizá vk7k-wp-sync en el remoto» — nunca asumir identidad por defecto.

### 1.3 Comparador

`VK7K_Sync_Theme_Preflight::check( $local, $remote )` → devuelve:

```json
{
  "verdict": "pass" | "confirm" | "block",
  "checks": [ { "id": "P1", "status": "ok|warn|fail", "message": "...", "actions": [ ... ] } ]
}
```

`verdict` es el **peor** de los chequeos. `block` = la UI no permite continuar; `confirm` = hay
que elegir explícitamente; `pass` = sigue sin fricción.

### 1.4 Enforzamiento en dos lados

- **Local**: el orquestador (`VK7K_Sync_Runner::push_theme`) exige `verdict != block` y una
  confirmación registrada. Sin eso, no construye el paquete.
- **Remoto**: el endpoint de importación rechaza un overlay FSE si su tema activo es **clásico**
  (validación de familia del lado que sí conoce su estado). Es la única comprobación que el local
  no puede hacer solo.
- No se confía en la UI: cualquier llamada directa al AJAX pasa por el mismo comparador.

---

## 2. Los seis chequeos y **qué hace el usuario al toparse con el bloqueo**

| # | Condición | Veredicto | Qué ve el usuario | Acciones que se le ofrecen |
|---|---|---|---|---|
| **P1** | Mismo tema en ambos lados (`stylesheet` + `template`) | pass | nada | — |
| **P1b** | El remoto corre **otro tema** (instalado) | **block** | «En remoto está activo `storefront`; acá trabajás con `fusem-2026` (hijo de `twentytwentyfive`). El overlay no se aplicaría.» | **1.** Instalar lo que falte y activar `fusem-2026+twentytwentyfive` en remoto · **2.** Copiar sólo archivos y **no** activar (queda preparado, sin efecto visible) · **3.** Cancelar |
| **P1c** | El tema **no existe** en remoto | **block** | «`fusem-2026` no está instalado en remoto.» | **1.** Instalarlo desde local (ZIP del directorio) y activar · **2.** Instalarlo sin activar · **3.** Cancelar |
| **P2** | Falta el **padre** (caso hijo) | **block** | «El hijo depende de `twentytwentyfive`, que no está en remoto.» | **1.** Instalar el padre, luego el hijo, luego activar (secuencia) · **2.** Cancelar |
| **P3** | **Familia distinta** (bloque vs clásico) | **block duro, sin override** | «El remoto corre un tema clásico. El overlay FSE no existe allí y el clásico no existe acá: no hay nada portable.» | **1.** Cancelar (única). Enlace a la nota de migración a tema de bloques |
| **P4** | Versión del padre distinta | **block** | «`twentytwentyfive` local 1.0 · remoto 1.1. El overlay del local se escribió contra la 1.0; el remoto corre la 1.1.» + instrucción concreta | **1.** «¿Cómo lo arreglo?» → pasos: actualizar `twentytwentyfive` en local hasta la misma versión del remoto, verificar, reintentar · **2.** Cancelar. **No hay «continuar igual»** |
| **P5** | El remoto tiene **overlay propio** de ese tema | **confirm** | «El remoto tiene 12 filas de overlay que no existen acá y 3 que difieren (última: hoy 14:02). Si ese trabajo importa, **detené el push**: corresponde un pull completo con wp-sync.» | **1.** Sobrescribir el remoto (snapshot obligatorio antes) · **2.** Cancelar. **No hay «traer» ni «conservar el remoto»**: conservar es un pull completo, fuera de este flujo |
| **P6** | Portada estática con página inexistente en remoto | **confirm** | «`page_on_front` = 123 no existe en remoto: al activar, la portada quedaría vacía.» | **1.** Crear la página y asignarla · **2.** Activar sin portada · **3.** Cancelar |

**Regla de oro del bloqueo:** todo `block` muestra **qué hacer**, no sólo «error». Y nunca hay un
botón que salte el chequeo: para P1/P2/P3 la única salida es resolver o cancelar.

**P5 no compara fechas.** Con el flujo unidireccional, «quién es más nuevo» deja de ser una
decisión del plugin: si el remoto tiene overlay propio, el push **para** y avisa que el camino
correcto es un pull completo. Comparar relojes entre local y remoto era una invitación a perder
trabajo hecho en producción.

---

## 3. Flujo de ejecución (orden seguro)

```
[preflight] → (confirmaciones) → snapshot remoto → archivos → overlay → activar → verificar → reporte
```

1. Preflight (§1.3). `block` corta aquí.
2. Confirmaciones: cada `confirm` elegido queda registrado (quién, qué, cuándo). Hoy el único
   `confirm` del preflight es P5 (sobrescribir overlay ajeno).
3. **Snapshot** del remoto antes de escribir: overlay + `theme_mods` + tema activo (JSON en
   `/home/<user>/backups/`, con fecha). Sin snapshot no se ejecuta ningún `confirm`. Es un
   respaldo de una sola operación, no un historial: se conserva el último y se purga el resto.
4. Archivos del tema (padre, luego hijo) **sin activar**.
5. Overlay (filas FSE del namespace correcto + `theme_mods` + `custom_css`).
6. Activar.
7. Verificar: `active_theme` == esperado, front 200, nº de plantillas == local, `page_on_front` ok.
8. Reporte final con el resultado de cada paso y la ruta del snapshot.

**Por qué activar al final:** activar antes deja el sitio unos segundos con el tema por defecto.
Al revés, el cambio aparece ya vestido.

---

## 4. Plan de implementación (pasos pequeños, cada uno verificable)

| Paso | Qué | Archivos | Verificación |
|---|---|---|---|
| **1** | `Theme_State` local + CLI `wp vk7k-sync theme-state [--json]` | `includes/class-vk7k-sync-theme-state.php`, `class-vk7k-sync-cli.php` | compara contra la BD real de `sillasenmovimiento`; debe reportar `home` como «sólo archivo» y las 7 filas como de `twentytwentyfive` |
| **2** | Endpoint `theme/state` en remoto | `includes/class-vk7k-sync-api.php` | `curl` firmado contra un remoto de pruebas devuelve el JSON de §1.1 |
| **3** | `Theme_Preflight::check()` puro (sin IO) | `includes/class-vk7k-sync-theme-preflight.php` | tabla de casos unitarios: los 6 escenarios + 2 casos borde (sin overlay, endpoint ausente) |
| **4** | Enforcement local en `Runner::push_theme` + AJAX que devuelve el veredicto | `class-vk7k-sync-runner.php`, `class-vk7k-sync-core.php` | push con identidad distinta → rechazado con el mensaje de P1b; sin tocar el remoto |
| **5** | Enforcement remoto: rechazo de overlay FSE sobre tema clásico | `class-vk7k-sync-api.php` | import firmado con overlay sobre un remoto clásico → 4xx con mensaje claro |
| **6** | UI: modal del preflight con las acciones de §2 | `admin/` (JS + PHP de la vista) | los 6 escenarios a mano, un clic por acción |
| **7** | Snapshot remoto antes de sobrescribir | `class-vk7k-sync-api.php` | archivo en `/home/<user>/backups/` con fecha; sin él, el confirm no ejecuta |

**Versión:** 1.9.2 → **1.10.0** (feature nueva). Deploy a la flota y verificación de versión en cada
remoto, como siempre.

---

## 5. Cómo se prueba (sin tocar producción)

Banco de pruebas: **sitio local desechable** `qa-remote.test` (WordPress limpio en Laragon) con
`vk7k-wp-sync` instalado y pareado. Ahí se montan los seis escenarios:

| Escenario | Cómo se monta |
|---|---|
| P1 ok | mismo tema en ambos lados |
| P1b | en `qa-remote` activar `twentytwentyfive` (u otro) |
| P1c | borrar el tema hijo en `qa-remote` |
| P3 | en `qa-remote` activar un tema clásico (Storefront o TT1) |
| P4 | editar `Version:` en el `style.css` del padre en `qa-remote` |
| P5 | crear 2 filas `wp_template` propias en `qa-remote` (el preflight debe ofrecer **sólo** sobrescribir o cancelar, nunca traer) |
| P6 | poner `page_on_front` a un ID inexistente |

Aceptación de F1: **los seis escenarios dan el mensaje y las acciones de §2, y ninguno deja un
push que "parezca exitoso" sin efecto real.**

---

## 6. Riesgos y cómo se mitigan

| Riesgo | Mitigación |
|---|---|
| El remoto viejo no tiene el endpoint → falso verde | bloqueo con «actualizá el plugin en remoto» (degradación explícita) |
| El preflight se salta llamando al AJAX directamente | el comparador se ejecuta **dentro** del handler, no en la UI |
| Snapshot falla o no hay espacio | abortar antes de escribir (recordar el incidente de disco) |
| `page_on_front` apunta a una página que sí existe pero tiene otro slug | comparar por ID y avisar del cambio de slug |
| El usuario necesita avanzar y P4 lo bloquea | el mensaje da los pasos exactos para alinear el padre en local; no hay atajo que degrade el remoto |
| Deploy del endpoint a un remoto sin actualizar el plugin | se despliega con `push_plugin` a los tres remotos y se verifica versión antes de usar F1 |

---

## 7. Lo que queda fuera de F1 (a propósito)

- **F2**: qué manda cuando hay fila y archivo (contenido + fecha) y resolución de
  `core/pattern` / `core/template-part`. Sin F2, empujar una plantilla consolidada sigue sin
  efecto aunque el preflight pase.
- **F3**: `push-theme` atómico con verificación en las 7 capas.
- **F4**: dry-run con diff.
- **F5**: detección de exportación pendiente de CBT.

Orden real de valor: **F1 protege** (evita el éxito falso), **F2 arregla** el bug que reportaste,
**F3** lo vuelve atómico. Se puede parar después de cualquiera de las tres.
