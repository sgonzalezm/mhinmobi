<?php
// includes/calendar_functions.php
// Funciones para el manejo del calendario

if (!function_exists('crearEventoCalendario')) {

    /**
     * Crear un nuevo evento en el calendario
     */
    function crearEventoCalendario($conn, $datos) {
        try {
            // Validar datos requeridos
            if (empty($datos['title']) || empty($datos['start_datetime'])) {
                return ['success' => false, 'message' => 'Título y fecha/hora son requeridos'];
            }

            // Asignar valores por defecto
            $datos['user_id'] = $datos['user_id'] ?? $_SESSION['user_id'] ?? null;
            $datos['status'] = $datos['status'] ?? 'pending';
            $datos['all_day'] = $datos['all_day'] ?? 0;
            $datos['color'] = $datos['color'] ?? obtenerColorPorTipo($datos['event_type'] ?? 'other');
            $datos['recurring'] = $datos['recurring'] ?? 0;

            if (empty($datos['user_id'])) {
                return ['success' => false, 'message' => 'Usuario no autenticado'];
            }

            $sql = "INSERT INTO calendar_events (
                property_id, tenant_id, user_id, title, description, event_type,
                start_datetime, end_datetime, all_day, status, color,
                location, contact_name, contact_phone, contact_email,
                reminder_minutes, recurring, recurrence_pattern, recurrence_end_date,
                parent_event_id, notes
            ) VALUES (
                :property_id, :tenant_id, :user_id, :title, :description, :event_type,
                :start_datetime, :end_datetime, :all_day, :status, :color,
                :location, :contact_name, :contact_phone, :contact_email,
                :reminder_minutes, :recurring, :recurrence_pattern, :recurrence_end_date,
                :parent_event_id, :notes
            )";

            $stmt = $conn->prepare($sql);
            $stmt->execute([
                ':property_id' => $datos['property_id'] ?? null,
                ':tenant_id' => $datos['tenant_id'] ?? null,
                ':user_id' => $datos['user_id'],
                ':title' => $datos['title'],
                ':description' => $datos['description'] ?? null,
                ':event_type' => $datos['event_type'] ?? 'other',
                ':start_datetime' => $datos['start_datetime'],
                ':end_datetime' => $datos['end_datetime'] ?? null,
                ':all_day' => $datos['all_day'],
                ':status' => $datos['status'],
                ':color' => $datos['color'],
                ':location' => $datos['location'] ?? null,
                ':contact_name' => $datos['contact_name'] ?? null,
                ':contact_phone' => $datos['contact_phone'] ?? null,
                ':contact_email' => $datos['contact_email'] ?? null,
                ':reminder_minutes' => $datos['reminder_minutes'] ?? 30,
                ':recurring' => $datos['recurring'],
                ':recurrence_pattern' => $datos['recurrence_pattern'] ?? null,
                ':recurrence_end_date' => $datos['recurrence_end_date'] ?? null,
                ':parent_event_id' => $datos['parent_event_id'] ?? null,
                ':notes' => $datos['notes'] ?? null
            ]);

            $event_id = $conn->lastInsertId();

            // Si es recurrente, generar las instancias
            if (!empty($datos['recurring']) && !empty($datos['recurrence_pattern'])) {
                generarEventosRecurrentes($conn, $event_id, $datos);
            }

            // Crear recordatorios si tiene minutos configurados
            if (!empty($datos['reminder_minutes']) && $datos['reminder_minutes'] > 0) {
                crearRecordatorio($conn, $event_id, $datos['start_datetime'], $datos['reminder_minutes']);
            }

            return ['success' => true, 'event_id' => $event_id, 'message' => 'Evento creado exitosamente'];

        } catch (PDOException $e) {
            error_log("Error al crear evento calendario: " . $e->getMessage());
            return ['success' => false, 'message' => 'Error al crear el evento: ' . $e->getMessage()];
        }
    }

    /**
     * Obtener eventos en un rango de fechas
     */
    function obtenerEventosCalendario($conn, $fecha_inicio, $fecha_fin, $filtros = []) {
        try {
            $sql = "SELECT e.*, 
                    p.title as property_title, 
                    p.address_municipality as property_municipality,
                    t.name as tenant_name,
                    t.email as tenant_email,
                    CONCAT(u.name, ' ', u.last_name) as user_fullname
                    FROM calendar_events e
                    LEFT JOIN properties p ON e.property_id = p.id
                    LEFT JOIN tenants t ON e.tenant_id = t.id
                    LEFT JOIN users u ON e.user_id = u.id
                    WHERE e.user_id = :user_id 
                    AND e.start_datetime BETWEEN :fecha_inicio AND :fecha_fin";

            $params = [
                ':user_id' => $_SESSION['user_id'],
                ':fecha_inicio' => $fecha_inicio,
                ':fecha_fin' => $fecha_fin
            ];

            // Aplicar filtros
            if (!empty($filtros['event_type'])) {
                $sql .= " AND e.event_type = :event_type";
                $params[':event_type'] = $filtros['event_type'];
            }

            if (!empty($filtros['property_id'])) {
                $sql .= " AND e.property_id = :property_id";
                $params[':property_id'] = $filtros['property_id'];
            }

            if (!empty($filtros['status'])) {
                $sql .= " AND e.status = :status";
                $params[':status'] = $filtros['status'];
            }

            $sql .= " ORDER BY e.start_datetime ASC";

            $stmt = $conn->prepare($sql);
            $stmt->execute($params);

            return $stmt->fetchAll(PDO::FETCH_ASSOC);

        } catch (PDOException $e) {
            error_log("Error al obtener eventos: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Obtener eventos próximos (para dashboard)
     */
    function obtenerEventosProximos($conn, $limite = 5) {
        try {
            $sql = "SELECT e.*, 
                    p.title as property_title,
                    t.name as tenant_name
                    FROM calendar_events e
                    LEFT JOIN properties p ON e.property_id = p.id
                    LEFT JOIN tenants t ON e.tenant_id = t.id
                    WHERE e.user_id = :user_id 
                    AND e.start_datetime >= NOW() 
                    AND e.status NOT IN ('cancelled', 'completed')
                    ORDER BY e.start_datetime ASC 
                    LIMIT :limite";

            $stmt = $conn->prepare($sql);
            $stmt->bindParam(':user_id', $_SESSION['user_id']);
            $stmt->bindParam(':limite', $limite, PDO::PARAM_INT);
            $stmt->execute();

            return $stmt->fetchAll(PDO::FETCH_ASSOC);

        } catch (PDOException $e) {
            error_log("Error al obtener eventos próximos: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Obtener eventos de vencimiento próximos
     */
    function obtenerVencimientosProximos($conn, $dias = 30) {
        try {
            $sql = "SELECT e.*, 
                    p.title as property_title,
                    p.address_municipality as property_municipality,
                    t.name as tenant_name
                    FROM calendar_events e
                    LEFT JOIN properties p ON e.property_id = p.id
                    LEFT JOIN tenants t ON e.tenant_id = t.id
                    WHERE e.user_id = :user_id 
                    AND e.event_type = 'expiration'
                    AND e.start_datetime BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL :dias DAY)
                    AND e.status NOT IN ('cancelled', 'completed')
                    ORDER BY e.start_datetime ASC";

            $stmt = $conn->prepare($sql);
            $stmt->execute([
                ':user_id' => $_SESSION['user_id'],
                ':dias' => $dias
            ]);

            return $stmt->fetchAll(PDO::FETCH_ASSOC);

        } catch (PDOException $e) {
            error_log("Error al obtener vencimientos: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Obtener eventos por mes (para vista de calendario)
     */
    function obtenerEventosPorMes($conn, $mes, $anio) {
        $fecha_inicio = date('Y-m-d 00:00:00', strtotime("$anio-$mes-01"));
        $fecha_fin = date('Y-m-d 23:59:59', strtotime("$anio-$mes-01 +1 month -1 day"));

        return obtenerEventosCalendario($conn, $fecha_inicio, $fecha_fin);
    }

    /**
     * Actualizar evento
     */
    function actualizarEventoCalendario($conn, $event_id, $datos) {
        try {
            $fields = [];
            $params = [':id' => $event_id];

            $allowed_fields = [
                'title', 'description', 'event_type', 'start_datetime', 'end_datetime',
                'all_day', 'status', 'color', 'location', 'contact_name',
                'contact_phone', 'contact_email', 'reminder_minutes', 'notes'
            ];

            foreach ($datos as $key => $value) {
                if (in_array($key, $allowed_fields)) {
                    $fields[] = "$key = :$key";
                    $params[":$key"] = $value;
                }
            }

            if (empty($fields)) {
                return ['success' => false, 'message' => 'No hay datos para actualizar'];
            }

            $sql = "UPDATE calendar_events SET " . implode(', ', $fields) . " WHERE id = :id AND user_id = :user_id";
            $params[':user_id'] = $_SESSION['user_id'];

            $stmt = $conn->prepare($sql);
            $stmt->execute($params);

            return ['success' => true, 'message' => 'Evento actualizado exitosamente'];

        } catch (PDOException $e) {
            error_log("Error al actualizar evento: " . $e->getMessage());
            return ['success' => false, 'message' => 'Error al actualizar el evento'];
        }
    }

    /**
     * Cambiar estado de evento
     */
    function cambiarEstadoEvento($conn, $event_id, $estado) {
        try {
            $sql = "UPDATE calendar_events SET status = :status WHERE id = :id AND user_id = :user_id";
            $stmt = $conn->prepare($sql);
            $stmt->execute([
                ':id' => $event_id,
                ':status' => $estado,
                ':user_id' => $_SESSION['user_id']
            ]);

            return ['success' => true, 'message' => 'Estado actualizado'];

        } catch (PDOException $e) {
            error_log("Error al cambiar estado: " . $e->getMessage());
            return ['success' => false, 'message' => 'Error al cambiar el estado'];
        }
    }

    /**
     * Eliminar evento
     */
    function eliminarEventoCalendario($conn, $event_id) {
        try {
            $sql = "DELETE FROM calendar_events WHERE id = :id AND user_id = :user_id";
            $stmt = $conn->prepare($sql);
            $stmt->execute([
                ':id' => $event_id,
                ':user_id' => $_SESSION['user_id']
            ]);

            return ['success' => true, 'message' => 'Evento eliminado'];

        } catch (PDOException $e) {
            error_log("Error al eliminar evento: " . $e->getMessage());
            return ['success' => false, 'message' => 'Error al eliminar el evento'];
        }
    }

    /**
     * Obtener color según tipo de evento
     */
    function obtenerColorPorTipo($tipo) {
        $colores = [
            'visit' => '#10b981',      // Verde - Visitas
            'expiration' => '#ef4444', // Rojo - Vencimientos
            'maintenance' => '#f59e0b',// Naranja - Mantenimiento
            'contract' => '#3b82f6',   // Azul - Contratos
            'meeting' => '#8b5cf6',    // Morado - Reuniones
            'other' => '#6b7280'       // Gris - Otros
        ];

        return $colores[$tipo] ?? '#6b7280';
    }

    /**
     * Obtener ícono según tipo de evento
     */
    function obtenerIconoPorTipo($tipo) {
        $iconos = [
            'visit' => 'fa-calendar-check',
            'expiration' => 'fa-clock',
            'maintenance' => 'fa-tools',
            'contract' => 'fa-file-contract',
            'meeting' => 'fa-users',
            'other' => 'fa-calendar'
        ];

        return $iconos[$tipo] ?? 'fa-calendar';
    }

    /**
     * Obtener clase CSS según estado
     */
    function obtenerClaseEstadoEvento($estado) {
        $clases = [
            'pending' => 'warning',
            'confirmed' => 'info',
            'completed' => 'success',
            'cancelled' => 'danger',
            'rescheduled' => 'secondary'
        ];

        return $clases[$estado] ?? 'secondary';
    }

    /**
     * Generar eventos recurrentes
     */
    function generarEventosRecurrentes($conn, $parent_id, $datos) {
        try {
            $end_date = new DateTime($datos['recurrence_end_date'] ?? $datos['start_datetime'] . ' +1 year');
            $current = new DateTime($datos['start_datetime']);
            $interval = obtenerIntervaloRecurrencia($datos['recurrence_pattern']);

            if (!$interval) return;

            $contador = 0;
            while ($current <= $end_date && $contador < 100) { // Límite de seguridad
                $current->add($interval);
                if ($current > $end_date) break;

                // Crear evento hijo
                $child_data = $datos;
                $child_data['start_datetime'] = $current->format('Y-m-d H:i:s');
                
                if (!empty($datos['end_datetime'])) {
                    $end = new DateTime($datos['end_datetime']);
                    $diff = $end->diff(new DateTime($datos['start_datetime']));
                    $child_end = clone $current;
                    $child_end->add($diff);
                    $child_data['end_datetime'] = $child_end->format('Y-m-d H:i:s');
                }
                
                $child_data['recurring'] = 0;
                $child_data['parent_event_id'] = $parent_id;
                $child_data['recurrence_pattern'] = null;
                $child_data['recurrence_end_date'] = null;

                crearEventoCalendario($conn, $child_data);
                $contador++;
            }

        } catch (Exception $e) {
            error_log("Error al generar eventos recurrentes: " . $e->getMessage());
        }
    }

    /**
     * Obtener intervalo de recurrencia
     */
    function obtenerIntervaloRecurrencia($pattern) {
        switch ($pattern) {
            case 'daily': return new DateInterval('P1D');
            case 'weekly': return new DateInterval('P1W');
            case 'monthly': return new DateInterval('P1M');
            case 'yearly': return new DateInterval('P1Y');
            default: return null;
        }
    }

    /**
     * Crear recordatorio para un evento
     */
    function crearRecordatorio($conn, $event_id, $start_datetime, $minutes_before) {
        try {
            $reminder_time = new DateTime($start_datetime);
            $reminder_time->sub(new DateInterval('PT' . $minutes_before . 'M'));

            // Solo crear si el recordatorio es en el futuro
            if ($reminder_time > new DateTime()) {
                $sql = "INSERT INTO event_reminders (event_id, reminder_time, sent) 
                        VALUES (:event_id, :reminder_time, 0)";
                $stmt = $conn->prepare($sql);
                $stmt->execute([
                    ':event_id' => $event_id,
                    ':reminder_time' => $reminder_time->format('Y-m-d H:i:s')
                ]);
            }

        } catch (Exception $e) {
            error_log("Error al crear recordatorio: " . $e->getMessage());
        }
    }

    /**
     * Procesar recordatorios pendientes (ejecutar en cron)
     */
    function procesarRecordatorios($conn) {
        try {
            $sql = "SELECT r.*, e.title, e.contact_email, e.contact_phone 
                    FROM event_reminders r
                    JOIN calendar_events e ON r.event_id = e.id
                    WHERE r.sent = 0 
                    AND r.reminder_time <= NOW()";

            $stmt = $conn->prepare($sql);
            $stmt->execute();
            $reminders = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($reminders as $reminder) {
                // Aquí implementar el envío de notificaciones
                // Email, SMS, Push, etc.
                
                // Marcar como enviado
                $sql_update = "UPDATE event_reminders SET sent = 1, sent_at = NOW() WHERE id = :id";
                $stmt_update = $conn->prepare($sql_update);
                $stmt_update->execute([':id' => $reminder['id']]);

                // Opcional: Registrar en log
                error_log("Recordatorio enviado para evento: " . $reminder['title']);
            }

            return count($reminders);

        } catch (PDOException $e) {
            error_log("Error al procesar recordatorios: " . $e->getMessage());
            return 0;
        }
    }
}
?>