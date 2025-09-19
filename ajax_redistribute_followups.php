<?php
require_once 'db_config.php';
requireAdmin();

header('Content-Type: application/json');

$conn = getDBConnection();
$adminId = $_SESSION['admin_id'];

try {
    $input = json_decode(file_get_contents('php://input'), true);
    $action = $input['action'] ?? '';
    
    if ($action === 'redistribute_overdue') {
        // Find all overdue follow-ups for this admin
        $stmt = $conn->prepare("
            SELECT 
                fcl.id,
                fcl.name,
                fcl.mobile_no,
                fcl.follow_day,
                fcl.follow_slot,
                fcl.disposition,
                fcl.processed_at,
                DATE_ADD(fcl.processed_at, INTERVAL fcl.follow_day DAY) as original_follow_date,
                fb.batch_name
            FROM lv_final_call_logs fcl
            JOIN lv_file_batches fb ON fcl.batch_id = fb.id
            WHERE fb.admin_id = ?
            AND fcl.follow_day IS NOT NULL 
            AND fcl.follow_day > 0
            AND DATE_ADD(fcl.processed_at, INTERVAL fcl.follow_day DAY) < CURDATE()
            AND fcl.disposition NOT IN ('Completed', 'Closed', 'Cancelled', 'Not Interested')
            ORDER BY DATE_ADD(fcl.processed_at, INTERVAL fcl.follow_day DAY) ASC
        ");
        
        $stmt->bind_param("s", $adminId);
        $stmt->execute();
        $overdueFollowups = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        
        if (empty($overdueFollowups)) {
            echo json_encode([
                'success' => false,
                'message' => 'No overdue follow-ups found to redistribute'
            ]);
            exit;
        }
        
        // Get available telecallers for this admin
        $stmt = $conn->prepare("
            SELECT c.finqy_id, c.caller_name, COUNT(fcl.id) as current_workload
            FROM lv_callers c
            JOIN lv_admin_caller_mapping acm ON c.finqy_id = acm.finqy_id
            LEFT JOIN lv_final_call_logs fcl ON c.finqy_id = fcl.finqy_id 
                AND fcl.processed_at >= CURDATE() - INTERVAL 7 DAY
            WHERE acm.admin_id = ? AND c.is_active = 1
            GROUP BY c.finqy_id, c.caller_name
            ORDER BY current_workload ASC, c.caller_name
        ");
        
        $stmt->bind_param("s", $adminId);
        $stmt->execute();
        $availableCallers = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        
        if (empty($availableCallers)) {
            echo json_encode([
                'success' => false,
                'message' => 'No available telecallers found for redistribution'
            ]);
            exit;
        }
        
        $redistributedCount = 0;
        $callerIndex = 0;
        
        $conn->begin_transaction();
        
        try {
            foreach ($overdueFollowups as $followup) {
                // Assign to next caller (round-robin with workload balancing)
                $assignedCaller = $availableCallers[$callerIndex % count($availableCallers)];
                $callerIndex++;
                
                // Create a new call log entry for redistribution
                $newLogId = 'RFL' . date('YmdHis') . substr(uniqid(), -4);
                
                $stmt = $conn->prepare("
                    INSERT INTO lv_final_call_logs (
                        id, name, mobile_no, slot, batch_id, finqy_id, 
                        disposition, follow_day, follow_slot, remarks, processed_at
                    ) VALUES (?, ?, ?, 1, (SELECT batch_id FROM lv_final_call_logs WHERE id = ?), ?, 
                             'Follow-up Redistribution', 1, 1, 
                             CONCAT('Redistributed overdue follow-up from ', ?, '. Original due: ', ?), 
                             NOW())
                ");
                
                $stmt->bind_param("sssssss", 
                    $newLogId,
                    $followup['name'],
                    $followup['mobile_no'], 
                    $followup['id'],
                    $assignedCaller['finqy_id'],
                    $followup['original_follow_date'],
                    $followup['original_follow_date']
                );
                
                if ($stmt->execute()) {
                    // Mark original follow-up as redistributed
                    $updateStmt = $conn->prepare("
                        UPDATE lv_final_call_logs 
                        SET remarks = CONCAT(IFNULL(remarks, ''), '\n[', NOW(), '] REDISTRIBUTED to ', ?, ' (', ?, ')')
                        WHERE id = ?
                    ");
                    $updateStmt->bind_param("sss", 
                        $assignedCaller['caller_name'],
                        $assignedCaller['finqy_id'],
                        $followup['id']
                    );
                    $updateStmt->execute();
                    $updateStmt->close();
                    
                    $redistributedCount++;
                }
                $stmt->close();
            }
            
            $conn->commit();
            
            // Log the redistribution action
            error_log("Admin $adminId redistributed $redistributedCount overdue follow-ups");
            
            echo json_encode([
                'success' => true,
                'count' => $redistributedCount,
                'message' => "Successfully redistributed $redistributedCount overdue follow-ups"
            ]);
            
        } catch (Exception $e) {
            $conn->rollback();
            throw $e;
        }
        
    } else {
        echo json_encode([
            'success' => false,
            'message' => 'Invalid action'
        ]);
    }
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Error: ' . $e->getMessage()
    ]);
} finally {
    if ($conn) {
        $conn->close();
    }
}
?>