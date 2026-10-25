<?php
// App 1 - CRUD de productos en PHP nativo (PDO + MySQL), desplegada en Cóndor Cloud.
declare(strict_types=1);
require __DIR__ . '/db.php';

header("Content-Security-Policy: default-src 'none'; style-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

// CSRF sin sesión (double-submit cookie): funciona igual con varias réplicas detrás del Ingress.
$csrf = $_COOKIE['csrf'] ?? '';
if (!preg_match('/^[a-f0-9]{64}$/', $csrf)) {
    $csrf = bin2hex(random_bytes(32));
    $https = ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    setcookie('csrf', $csrf, ['httponly' => true, 'samesite' => 'Strict', 'secure' => $https, 'path' => '/']);
}

$mensajes = ['creado' => 'Producto creado.', 'actualizado' => 'Producto actualizado.', 'eliminado' => 'Producto eliminado.'];
$errores = [];
$form = ['id' => '', 'nombre' => '', 'descripcion' => '', 'precio' => '', 'stock' => ''];

function validar(array $in, array &$errores): array
{
    $p = [
        'nombre' => trim((string) ($in['nombre'] ?? '')),
        'descripcion' => trim((string) ($in['descripcion'] ?? '')),
        'precio' => trim((string) ($in['precio'] ?? '')),
        'stock' => trim((string) ($in['stock'] ?? '')),
    ];
    if ($p['nombre'] === '' || mb_strlen($p['nombre']) > 120) {
        $errores[] = 'El nombre es obligatorio (máximo 120 caracteres).';
    }
    if (mb_strlen($p['descripcion']) > 2000) {
        $errores[] = 'La descripción admite hasta 2000 caracteres.';
    }
    if (!is_numeric($p['precio']) || (float) $p['precio'] < 0 || (float) $p['precio'] > 99999999) {
        $errores[] = 'El precio debe ser un número mayor o igual a 0.';
    }
    if (filter_var($p['stock'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 1000000000]]) === false) {
        $errores[] = 'El stock debe ser un entero mayor o igual a 0.';
    }
    return $p;
}

try {
    $pdo = db();

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!hash_equals($csrf, (string) ($_POST['csrf'] ?? ''))) {
            http_response_code(403);
            exit('Token CSRF inválido. Recarga la página.');
        }
        $accion = $_POST['accion'] ?? '';
        $id = filter_var($_POST['id'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        if ($accion === 'eliminar' && $id) {
            $pdo->prepare('DELETE FROM productos WHERE id = ?')->execute([$id]);
            header('Location: ./?ok=eliminado', true, 303);
            exit;
        }
        if ($accion === 'crear' || ($accion === 'actualizar' && $id)) {
            $p = validar($_POST, $errores);
            if (!$errores) {
                $args = [$p['nombre'], $p['descripcion'] ?: null, $p['precio'], (int) $p['stock']];
                if ($accion === 'crear') {
                    $pdo->prepare('INSERT INTO productos (nombre, descripcion, precio, stock) VALUES (?, ?, ?, ?)')->execute($args);
                } else {
                    $args[] = $id;
                    $pdo->prepare('UPDATE productos SET nombre = ?, descripcion = ?, precio = ?, stock = ? WHERE id = ?')->execute($args);
                }
                header('Location: ./?ok=' . ($accion === 'crear' ? 'creado' : 'actualizado'), true, 303);
                exit;
            }
            $form = ['id' => $id ?: ''] + $p;
        }
    } elseif (isset($_GET['editar'])) {
        $st = $pdo->prepare('SELECT * FROM productos WHERE id = ?');
        $st->execute([(int) $_GET['editar']]);
        $form = $st->fetch() ?: $form;
    }

    $q = trim((string) ($_GET['q'] ?? ''));
    if ($q !== '') {
        $st = $pdo->prepare('SELECT * FROM productos WHERE nombre LIKE ? ORDER BY id DESC');
        $st->execute(['%' . $q . '%']);
    } else {
        $st = $pdo->query('SELECT * FROM productos ORDER BY id DESC');
    }
    $productos = $st->fetchAll();
    $resumen = $pdo->query('SELECT COUNT(*) AS n, COALESCE(SUM(precio * stock), 0) AS valor FROM productos')->fetch();
} catch (PDOException | RuntimeException $ex) {
    error_log('Error de base de datos: ' . $ex->getMessage());
    http_response_code(503);
    $fatal = 'No se pudo conectar con la base de datos administrada. Reintenta en unos segundos.';
}
$editando = (string) $form['id'] !== '';
// Nombre comercial de la PaaS: lo define el panel en la variable PLATAFORMA
$plataforma = getenv('PLATAFORMA') ?: 'la PaaS';
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Inventario · <?= e($plataforma) ?></title>
<link rel="stylesheet" href="estilos.css">
</head>
<body>
<header>
  <div class="marca">Inventario <span>PHP + MySQL · v1.1</span></div>
  <div class="infra">Desplegado en <b><?= e($plataforma) ?></b> · pod <code><?= e(gethostname()) ?></code></div>
</header>
<main>
<?php if (isset($fatal)): ?>
  <p class="alerta error"><?= e($fatal) ?></p>
<?php else: ?>
  <?php if (isset($mensajes[$_GET['ok'] ?? ''])): ?><p class="alerta ok"><?= e($mensajes[$_GET['ok']]) ?></p><?php endif; ?>
  <?php foreach ($errores as $err): ?><p class="alerta error"><?= e($err) ?></p><?php endforeach; ?>

  <section class="tarjeta">
    <h2><?= $editando ? 'Editar producto #' . e((string) $form['id']) : 'Nuevo producto' ?></h2>
    <form method="post" action="./" class="formulario">
      <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
      <input type="hidden" name="accion" value="<?= $editando ? 'actualizar' : 'crear' ?>">
      <input type="hidden" name="id" value="<?= e((string) $form['id']) ?>">
      <label>Nombre<input name="nombre" maxlength="120" required value="<?= e($form['nombre']) ?>"></label>
      <label>Precio<input name="precio" type="number" step="0.01" min="0" required value="<?= e((string) $form['precio']) ?>"></label>
      <label>Stock<input name="stock" type="number" step="1" min="0" required value="<?= e((string) $form['stock']) ?>"></label>
      <label class="ancho">Descripción<textarea name="descripcion" rows="2" maxlength="2000"><?= e($form['descripcion']) ?></textarea></label>
      <div class="ancho acciones">
        <button class="primario" type="submit"><?= $editando ? 'Guardar cambios' : 'Crear producto' ?></button>
        <?php if ($editando): ?><a class="boton" href="./">Cancelar</a><?php endif; ?>
      </div>
    </form>
  </section>

  <section class="tarjeta">
    <div class="fila">
      <h2>Productos <small><?= (int) $resumen['n'] ?> en total · valor del inventario $<?= number_format((float) $resumen['valor'], 2) ?></small></h2>
      <form method="get" action="./" class="buscar"><input name="q" placeholder="Buscar por nombre" value="<?= e($q) ?>"><button type="submit">Buscar</button></form>
    </div>
    <?php if (!$productos): ?>
      <p class="vacio"><?= $q !== '' ? 'Sin resultados para “' . e($q) . '”.' : 'Todavía no hay productos. Crea el primero con el formulario.' ?></p>
    <?php else: ?>
    <div class="tabla">
    <table>
      <thead><tr><th>ID</th><th>Nombre</th><th>Descripción</th><th class="num">Precio</th><th class="num">Stock</th><th>Actualizado</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($productos as $p): ?>
        <tr>
          <td><?= (int) $p['id'] ?></td>
          <td><?= e($p['nombre']) ?></td>
          <td class="desc"><?= e($p['descripcion']) ?></td>
          <td class="num">$<?= number_format((float) $p['precio'], 2) ?></td>
          <td class="num"><?= (int) $p['stock'] ?></td>
          <td><?= e($p['actualizado_en']) ?></td>
          <td class="acciones">
            <a class="boton" href="?editar=<?= (int) $p['id'] ?>">Editar</a>
            <form method="post" action="./">
              <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
              <input type="hidden" name="accion" value="eliminar">
              <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
              <button class="peligro" type="submit">Eliminar</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <?php endif; ?>
  </section>
<?php endif; ?>
</main>
</body>
</html>
