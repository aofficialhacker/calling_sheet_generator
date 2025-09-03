/**
 * Follow-up Notifications JavaScript Module
 * Handles real-time notifications for team leaders
 */

class FollowupNotifications {
    constructor() {
        this.checkInterval = 30000; // Check every 30 seconds for testing
        this.intervalId = null;
        this.notificationCount = 0;
        this.lastCheck = Date.now();
        this.notifications = [];
        this.isVisible = true;
        
        this.init();
    }
    
    init() {
        // Request notification permission
        this.requestNotificationPermission();
        
        // Start checking for notifications
        this.startNotificationChecker();
        
        // Handle page visibility changes
        this.handleVisibilityChange();
        
        // Add notification UI to page
        this.createNotificationUI();
        
        // Initial check
        this.checkNotifications();
    }
    
    requestNotificationPermission() {
        if ('Notification' in window && Notification.permission === 'default') {
            Notification.requestPermission().then(permission => {
                console.log('Notification permission:', permission);
            });
        }
    }
    
    startNotificationChecker() {
        // Clear any existing interval
        if (this.intervalId) {
            clearInterval(this.intervalId);
        }
        
        // Set up new interval
        this.intervalId = setInterval(() => {
            this.checkNotifications();
        }, this.checkInterval);
        
        // Check when tab becomes visible again
        document.addEventListener('visibilitychange', () => {
            if (!document.hidden) {
                this.checkNotifications();
            }
        });
    }
    
    handleVisibilityChange() {
        document.addEventListener('visibilitychange', () => {
            this.isVisible = !document.hidden;
            if (this.isVisible) {
                // Page is visible, check for new notifications
                this.checkNotifications();
            }
        });
    }
    
    async checkNotifications() {
        try {
            const response = await fetch('ajax_followup_notifications.php?action=check_notifications');
            const data = await response.json();
            
            console.log('📡 Notification API Response:', data);
            
            if (data.success) {
                console.log(`📊 Found ${data.notifications?.length || 0} notifications`);
                this.processNotifications(data.notifications || []);
                this.updateNotificationUI(data.notifications || []);
            } else {
                console.error('Failed to check notifications:', data.message);
            }
        } catch (error) {
            console.error('Error checking notifications:', error);
        }
    }
    
    processNotifications(notifications) {
        const newNotifications = notifications.filter(notification => 
            !this.notifications.find(existing => existing.id === notification.id)
        );
        
        // Show browser notifications for new critical/high urgency items
        newNotifications.forEach(notification => {
            if (notification.urgency === 'critical' || notification.urgency === 'high') {
                this.showBrowserNotification(notification);
            }
        });
        
        // Show enhanced alerts for critical overdue items
        const criticalOverdue = notifications.filter(n => n.urgency === 'critical' && n.minutes_until_due <= 0);
        if (criticalOverdue.length > 0) {
            this.showCriticalOverdueAlert(criticalOverdue);
        }
        
        // Show page-level alerts for upcoming follow-ups
        const upcomingUrgent = notifications.filter(n => n.urgency === 'high' && n.minutes_until_due > 0 && n.minutes_until_due <= 15);
        if (upcomingUrgent.length > 0) {
            this.showUpcomingAlert(upcomingUrgent);
        }
        
        // Update internal notifications list
        this.notifications = notifications;
        this.notificationCount = notifications.length;
    }
    
    showBrowserNotification(notification) {
        if ('Notification' in window && Notification.permission === 'granted') {
            const title = notification.urgency === 'critical' 
                ? '🔴 OVERDUE Follow-up' 
                : '⚠️ Follow-up Due Soon';
            
            const body = `Customer: ${notification.customer_name}\nTime: ${notification.display_time}\nDisposition: ${notification.disposition_name}`;
            
            const browserNotification = new Notification(title, {
                body: body,
                icon: 'data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16"><path fill="red" d="M8 16A8 8 0 1 1 8 0a8 8 0 0 1 0 16zM8 4a.5.5 0 0 1 .5.5v3a.5.5 0 0 1-1 0v-3A.5.5 0 0 1 8 4zM8 10a.5.5 0 1 1 0 1 .5.5 0 0 1 0-1z"/></svg>',
                tag: `followup-${notification.id}`,
                requireInteraction: notification.urgency === 'critical',
                actions: [
                    { action: 'complete', title: 'Mark Complete' },
                    { action: 'snooze', title: 'Snooze 15min' }
                ]
            });
            
            // Handle notification clicks
            browserNotification.onclick = () => {
                window.focus();
                this.showNotificationDetail(notification);
                browserNotification.close();
            };
            
            // Auto-close after 10 seconds for non-critical
            if (notification.urgency !== 'critical') {
                setTimeout(() => {
                    browserNotification.close();
                }, 10000);
            }
        }
    }
    
    showCriticalOverdueAlert(overdueItems) {
        // Remove any existing critical alert
        const existingAlert = document.getElementById('criticalOverdueAlert');
        if (existingAlert) {
            existingAlert.remove();
        }
        
        // Play urgent sound if available
        this.playUrgentSound();
        
        // Create prominent page-level alert
        const alertHtml = `
            <div id="criticalOverdueAlert" class="position-fixed w-100 alert alert-danger border-0 m-0 py-3" 
                 style="top: 0; left: 0; z-index: 2000; border-radius: 0; animation: criticalPulse 2s infinite;">
                <div class="container-fluid">
                    <div class="row align-items-center">
                        <div class="col-auto">
                            <i class="bi bi-exclamation-triangle-fill fs-1 text-white" style="animation: shake 0.5s infinite;"></i>
                        </div>
                        <div class="col">
                            <h4 class="alert-heading mb-2 text-white fw-bold">
                                🚨 CRITICAL: ${overdueItems.length} OVERDUE Follow-up${overdueItems.length > 1 ? 's' : ''}!
                            </h4>
                            <div class="mb-2">
                                ${overdueItems.slice(0, 3).map(item => `
                                    <div class="mb-1">
                                        <strong>${item.customer_name}</strong> - ${item.disposition_name} 
                                        <span class="badge bg-white text-danger fw-bold ms-2">OVERDUE</span>
                                    </div>
                                `).join('')}
                                ${overdueItems.length > 3 ? `<div class="text-white-50">...and ${overdueItems.length - 3} more</div>` : ''}
                            </div>
                        </div>
                        <div class="col-auto">
                            <div class="btn-group">
                                <button class="btn btn-light btn-lg fw-bold" onclick="followupNotifications.openFollowUpCalendar()">
                                    <i class="bi bi-calendar-check-fill me-2"></i>Open Calendar
                                </button>
                                <button class="btn btn-outline-light" onclick="followupNotifications.dismissCriticalAlert()">
                                    <i class="bi bi-x-lg"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        `;
        
        document.body.insertAdjacentHTML('afterbegin', alertHtml);
        
        // Auto-dismiss after 30 seconds
        setTimeout(() => {
            this.dismissCriticalAlert();
        }, 30000);
    }
    
    showUpcomingAlert(upcomingItems) {
        // Remove any existing upcoming alert
        const existingAlert = document.getElementById('upcomingFollowupAlert');
        if (existingAlert) {
            existingAlert.remove();
        }
        
        // Create subtle but noticeable alert
        const alertHtml = `
            <div id="upcomingFollowupAlert" class="position-fixed alert alert-warning border-0 m-3 shadow-lg" 
                 style="top: 60px; right: 0; z-index: 1500; max-width: 400px; border-radius: 12px; animation: slideInRight 0.5s ease-out;">
                <div class="d-flex align-items-center">
                    <div class="me-3">
                        <i class="bi bi-bell-fill fs-3 text-warning" style="animation: gentleBounce 2s infinite;"></i>
                    </div>
                    <div class="flex-grow-1">
                        <h6 class="alert-heading mb-1 fw-bold">
                            ⏰ Follow-up${upcomingItems.length > 1 ? 's' : ''} Due Soon!
                        </h6>
                        <div class="small">
                            ${upcomingItems.slice(0, 2).map(item => `
                                <div class="mb-1">
                                    <strong>${item.customer_name}</strong> - Due ${item.display_time}
                                </div>
                            `).join('')}
                            ${upcomingItems.length > 2 ? `<div class="text-muted">+${upcomingItems.length - 2} more</div>` : ''}
                        </div>
                        <div class="mt-2">
                            <button class="btn btn-sm btn-warning fw-bold me-2" onclick="followupNotifications.openFollowUpCalendar()">
                                <i class="bi bi-calendar-check me-1"></i>View Calendar
                            </button>
                            <button class="btn btn-sm btn-outline-secondary" onclick="followupNotifications.dismissUpcomingAlert()">
                                Later
                            </button>
                        </div>
                    </div>
                    <button type="button" class="btn-close" onclick="followupNotifications.dismissUpcomingAlert()"></button>
                </div>
            </div>
        `;
        
        document.body.insertAdjacentHTML('afterbegin', alertHtml);
        
        // Auto-dismiss after 15 seconds
        setTimeout(() => {
            this.dismissUpcomingAlert();
        }, 15000);
    }
    
    playUrgentSound() {
        try {
            // Create audio context for urgent beep sound
            const audioContext = new (window.AudioContext || window.webkitAudioContext)();
            const oscillator = audioContext.createOscillator();
            const gainNode = audioContext.createGain();
            
            oscillator.connect(gainNode);
            gainNode.connect(audioContext.destination);
            
            // High-pitched urgent beep
            oscillator.frequency.setValueAtTime(800, audioContext.currentTime);
            gainNode.gain.setValueAtTime(0.3, audioContext.currentTime);
            
            oscillator.start();
            oscillator.stop(audioContext.currentTime + 0.2);
            
            // Second beep after short pause
            setTimeout(() => {
                const oscillator2 = audioContext.createOscillator();
                const gainNode2 = audioContext.createGain();
                
                oscillator2.connect(gainNode2);
                gainNode2.connect(audioContext.destination);
                
                oscillator2.frequency.setValueAtTime(900, audioContext.currentTime);
                gainNode2.gain.setValueAtTime(0.3, audioContext.currentTime);
                
                oscillator2.start();
                oscillator2.stop(audioContext.currentTime + 0.2);
            }, 300);
            
        } catch (error) {
            console.log('Audio not available:', error);
        }
    }
    
    dismissCriticalAlert() {
        const alert = document.getElementById('criticalOverdueAlert');
        if (alert) {
            alert.style.animation = 'fadeOut 0.5s ease-out';
            setTimeout(() => alert.remove(), 500);
        }
    }
    
    dismissUpcomingAlert() {
        const alert = document.getElementById('upcomingFollowupAlert');
        if (alert) {
            alert.style.animation = 'slideOutRight 0.5s ease-in';
            setTimeout(() => alert.remove(), 500);
        }
    }
    
    openFollowUpCalendar() {
        window.open('follow_up_calendar.php', '_blank');
        this.dismissCriticalAlert();
        this.dismissUpcomingAlert();
    }
    
    createNotificationUI() {
        // Check if UI already exists to prevent duplicates
        if (document.getElementById('notificationBell')) {
            return;
        }
        
        // Add CSS animations if not already present
        if (!document.getElementById('notification-styles')) {
            const style = document.createElement('style');
            style.id = 'notification-styles';
            style.textContent = `
                @keyframes pulse {
                    0% { transform: scale(1); }
                    50% { transform: scale(1.1); box-shadow: 0 0 20px rgba(220, 53, 69, 0.6); }
                    100% { transform: scale(1); }
                }
                
                @keyframes criticalPulse {
                    0% { background-color: #dc3545; box-shadow: 0 0 0 0 rgba(220, 53, 69, 0.7); }
                    50% { background-color: #c82333; box-shadow: 0 0 0 10px rgba(220, 53, 69, 0); }
                    100% { background-color: #dc3545; box-shadow: 0 0 0 0 rgba(220, 53, 69, 0); }
                }
                
                @keyframes shake {
                    0%, 100% { transform: translateX(0); }
                    25% { transform: translateX(-5px); }
                    75% { transform: translateX(5px); }
                }
                
                @keyframes slideInRight {
                    from { 
                        transform: translateX(100%); 
                        opacity: 0; 
                    }
                    to { 
                        transform: translateX(0); 
                        opacity: 1; 
                    }
                }
                
                @keyframes slideOutRight {
                    from { 
                        transform: translateX(0); 
                        opacity: 1; 
                    }
                    to { 
                        transform: translateX(100%); 
                        opacity: 0; 
                    }
                }
                
                @keyframes fadeOut {
                    from { opacity: 1; }
                    to { opacity: 0; }
                }
                
                @keyframes gentleBounce {
                    0%, 20%, 50%, 80%, 100% { transform: translateY(0); }
                    40% { transform: translateY(-10px); }
                    60% { transform: translateY(-5px); }
                }
                
                .notification-item:hover {
                    transform: translateX(5px);
                    transition: transform 0.2s ease-in-out;
                }
                
                /* Critical alert styling */
                #criticalOverdueAlert {
                    background: linear-gradient(45deg, #dc3545, #c82333) !important;
                    border: 3px solid #fff !important;
                    box-shadow: 0 8px 32px rgba(220, 53, 69, 0.3) !important;
                }
                
                /* Upcoming alert styling */
                #upcomingFollowupAlert {
                    background: linear-gradient(135deg, #fff3cd, #ffeaa7) !important;
                    border: 2px solid #ffc107 !important;
                    box-shadow: 0 6px 24px rgba(255, 193, 7, 0.2) !important;
                }
            `;
            document.head.appendChild(style);
        }
        
        // Create notification bell icon in the top navigation
        const notificationHtml = `
            <div class="notification-container position-relative me-3" style="z-index: 1000;">
                <button class="btn btn-outline-primary position-relative notification-bell-btn" id="notificationBell" type="button" 
                        data-bs-toggle="dropdown" aria-expanded="false" 
                        style="min-width: 45px; min-height: 38px;">
                    <i class="bi bi-bell text-primary" style="font-size: 1.2em;"></i>
                    <span class="notification-badge position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger" 
                          id="notificationCount" style="display: none; font-size: 0.7em; min-width: 18px;">0</span>
                </button>
                <div class="dropdown-menu dropdown-menu-end p-0" id="notificationDropdown" style="width: 350px; max-height: 400px; overflow-y: auto;">
                    <div class="dropdown-header d-flex justify-content-between align-items-center">
                        <span>Follow-up Notifications</span>
                        <button class="btn btn-sm btn-outline-secondary" onclick="followupNotifications.markAllAsRead()">
                            Mark All Read
                        </button>
                    </div>
                    <div id="notificationList">
                        <div class="dropdown-item-text text-center text-muted py-3">
                            Loading notifications...
                        </div>
                    </div>
                </div>
            </div>
        `;
        
        // Find the best place to insert the notification UI
        const navbar = document.querySelector('.btn-toolbar');
        if (navbar) {
            navbar.insertAdjacentHTML('afterbegin', notificationHtml);
        } else {
            // Try alternative locations
            const altNavbar = document.querySelector('.d-flex .btn-group');
            if (altNavbar && altNavbar.parentElement) {
                altNavbar.parentElement.insertAdjacentHTML('afterbegin', notificationHtml);
            } else {
                // Fallback: add to body if no suitable container found
                document.body.insertAdjacentHTML('afterbegin', `
                    <div class="position-fixed top-0 end-0 p-3" style="z-index: 1050;">
                        ${notificationHtml}
                    </div>
                `);
            }
        }
    }
    
    updateNotificationUI(notifications) {
        const countBadge = document.getElementById('notificationCount');
        const notificationList = document.getElementById('notificationList');
        
        if (!countBadge || !notificationList) return;
        
        // Debug: Log notification data
        console.log('🔔 Updating notification UI with:', notifications);
        notifications.forEach(n => {
            console.log(`📋 Notification: ${n.customer_name} - Urgency: ${n.urgency} - Minutes until due: ${n.minutes_until_due}`);
        });
        
        // Update count badge and bell styling
        const notificationBell = document.getElementById('notificationBell');
        
        if (notifications.length > 0) {
            countBadge.textContent = notifications.length;
            countBadge.style.display = 'block';
            
            // Change bell color and animation based on highest urgency
            const hasCritical = notifications.some(n => n.urgency === 'critical' || n.minutes_until_due <= 0);
            const hasHigh = notifications.some(n => n.urgency === 'high' || (n.minutes_until_due > 0 && n.minutes_until_due <= 15));
            const hasMedium = notifications.some(n => n.urgency === 'medium' || (n.minutes_until_due > 15 && n.minutes_until_due <= 60));
            
            console.log(`🎨 Bell colors - Critical: ${hasCritical}, High: ${hasHigh}, Medium: ${hasMedium}`);
            
            if (notificationBell) {
                // Reset classes and maintain dropdown functionality
                notificationBell.className = 'btn position-relative notification-bell-btn';
                notificationBell.setAttribute('data-bs-toggle', 'dropdown');
                notificationBell.style.minWidth = '45px';
                notificationBell.style.minHeight = '38px';
                
                if (hasCritical) {
                    console.log('🔴 Setting bell to CRITICAL (red)');
                    notificationBell.classList.add('btn-danger');
                    notificationBell.style.animation = 'pulse 1.5s infinite';
                    notificationBell.style.backgroundColor = '#dc3545';
                    notificationBell.style.borderColor = '#dc3545';
                    notificationBell.innerHTML = '<i class="bi bi-bell-fill text-white" style="font-size: 1.2em;"></i>';
                } else if (hasHigh) {
                    console.log('🟠 Setting bell to HIGH (orange)');
                    notificationBell.classList.add('btn-warning');
                    notificationBell.style.animation = 'none';
                    notificationBell.style.backgroundColor = '#fd7e14';
                    notificationBell.style.borderColor = '#fd7e14';
                    notificationBell.innerHTML = '<i class="bi bi-bell-fill text-white" style="font-size: 1.2em;"></i>';
                } else if (hasMedium) {
                    console.log('🔵 Setting bell to MEDIUM (blue)');
                    notificationBell.classList.add('btn-info');
                    notificationBell.style.animation = 'none';
                    notificationBell.style.backgroundColor = '#0dcaf0';
                    notificationBell.style.borderColor = '#0dcaf0';
                    notificationBell.innerHTML = '<i class="bi bi-bell-fill text-white" style="font-size: 1.2em;"></i>';
                } else {
                    console.log('🟢 Setting bell to LOW (green)');
                    notificationBell.classList.add('btn-success');
                    notificationBell.style.animation = 'none';
                    notificationBell.style.backgroundColor = '#198754';
                    notificationBell.style.borderColor = '#198754';
                    notificationBell.innerHTML = '<i class="bi bi-bell-fill text-white" style="font-size: 1.2em;"></i>';
                }
            }
        } else {
            countBadge.style.display = 'none';
            
            if (notificationBell) {
                console.log('⭕ No notifications - setting bell to outline');
                notificationBell.className = 'btn btn-outline-primary position-relative notification-bell-btn';
                notificationBell.setAttribute('data-bs-toggle', 'dropdown');
                notificationBell.style.animation = 'none';
                notificationBell.style.backgroundColor = '';
                notificationBell.style.borderColor = '';
                notificationBell.style.border = '2px solid #007bff';
                notificationBell.innerHTML = '<i class="bi bi-bell text-primary" style="font-size: 1.2em;"></i>';
            }
        }
        
        // Update notification list
        if (notifications.length === 0) {
            notificationList.innerHTML = `
                <div class="dropdown-item-text text-center text-muted py-3">
                    No notifications
                </div>
            `;
        } else {
            const notificationItems = notifications.map(notification => {
                const urgencyClass = {
                    'critical': 'danger',
                    'high': 'warning', 
                    'medium': 'info',
                    'low': 'secondary'
                }[notification.urgency] || 'secondary';
                
                const timeDisplay = notification.minutes_until_due <= 0 
                    ? 'OVERDUE' 
                    : `Due ${notification.display_time}`;
                
                const urgencyStyle = {
                    'critical': 'background: linear-gradient(90deg, #dc3545 0%, rgba(220,53,69,0.1) 100%); color: #721c24;',
                    'high': 'background: linear-gradient(90deg, #fd7e14 0%, rgba(253,126,20,0.1) 100%); color: #a55d2a;',
                    'medium': 'background: linear-gradient(90deg, #0dcaf0 0%, rgba(13,202,240,0.1) 100%); color: #067581;',
                    'low': 'background: linear-gradient(90deg, #6c757d 0%, rgba(108,117,125,0.1) 100%); color: #495057;'
                }[notification.urgency] || 'background: #f8f9fa; color: #495057;';
                
                return `
                    <div class="dropdown-item notification-item border-start border-${urgencyClass} border-4 mb-2" 
                         data-notification-id="${notification.id}"
                         style="${urgencyStyle} border-radius: 0 8px 8px 0; padding: 12px;">
                        <div class="d-flex justify-content-between align-items-start">
                            <div class="flex-grow-1">
                                <h6 class="mb-1 fw-bold">${notification.customer_name}</h6>
                                <p class="mb-1 small text-dark opacity-75">${notification.disposition_name}</p>
                                <small class="fw-bold">
                                    <i class="bi bi-clock-fill me-1"></i>${timeDisplay}
                                    ${notification.urgency === 'critical' ? '<i class="bi bi-exclamation-triangle-fill text-danger ms-2"></i>' : ''}
                                    ${notification.urgency === 'high' ? '<i class="bi bi-exclamation-circle-fill text-warning ms-2"></i>' : ''}
                                </small>
                            </div>
                            <div class="dropdown">
                                <button class="btn btn-sm btn-outline-secondary dropdown-toggle" 
                                        data-bs-toggle="dropdown">
                                    <i class="bi bi-three-dots"></i>
                                </button>
                                <ul class="dropdown-menu">
                                    <li><a class="dropdown-item" href="#" 
                                           onclick="followupNotifications.quickComplete('${notification.id}')">
                                        <i class="bi bi-check-circle me-2"></i>Mark Complete
                                    </a></li>
                                    <li><a class="dropdown-item" href="#" 
                                           onclick="followupNotifications.snoozeNotification('${notification.id}', 15)">
                                        <i class="bi bi-clock me-2"></i>Snooze 15min
                                    </a></li>
                                    <li><hr class="dropdown-divider"></li>
                                    <li><a class="dropdown-item" href="follow_up_calendar.php">
                                        <i class="bi bi-calendar-check me-2"></i>View Calendar
                                    </a></li>
                                </ul>
                            </div>
                        </div>
                    </div>
                `;
            }).join('');
            
            notificationList.innerHTML = notificationItems;
        }
    }
    
    async quickComplete(scheduleId) {
        try {
            const response = await fetch('ajax_followup_notifications.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    action: 'quick_update_status',
                    schedule_id: scheduleId,
                    status: 'completed',
                    remarks: 'Quick complete from notification'
                })
            });
            
            const data = await response.json();
            
            if (data.success) {
                this.showToast('Follow-up marked as completed', 'success');
                this.checkNotifications(); // Refresh notifications
            } else {
                this.showToast('Failed to update follow-up: ' + data.message, 'error');
            }
        } catch (error) {
            console.error('Error completing follow-up:', error);
            this.showToast('Error updating follow-up', 'error');
        }
    }
    
    async snoozeNotification(scheduleId, minutes) {
        try {
            const response = await fetch('ajax_followup_notifications.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    action: 'snooze_notification',
                    schedule_id: scheduleId,
                    snooze_minutes: minutes
                })
            });
            
            const data = await response.json();
            
            if (data.success) {
                this.showToast(`Follow-up snoozed for ${minutes} minutes`, 'info');
                this.checkNotifications(); // Refresh notifications
            } else {
                this.showToast('Failed to snooze notification: ' + data.message, 'error');
            }
        } catch (error) {
            console.error('Error snoozing notification:', error);
            this.showToast('Error snoozing notification', 'error');
        }
    }
    
    markAllAsRead() {
        // Make API call to mark all notifications as read
        fetch('ajax_followup_notifications.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                action: 'mark_all_read'
            })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                // Clear local notifications and update UI
                this.notifications = [];
                this.notificationCount = 0;
                this.updateNotificationUI([]);
                this.showToast(data.message, 'success');
            } else {
                this.showToast('Error marking notifications as read: ' + data.message, 'error');
            }
        })
        .catch(error => {
            console.error('Error marking all as read:', error);
            this.showToast('Error marking notifications as read', 'error');
        });
    }
    
    showToast(message, type = 'info') {
        // Create toast notification
        const toastId = 'toast-' + Date.now();
        const toastHtml = `
            <div class="toast align-items-center text-white bg-${type === 'error' ? 'danger' : type === 'success' ? 'success' : 'info'} border-0" 
                 id="${toastId}" role="alert" aria-live="assertive" aria-atomic="true">
                <div class="d-flex">
                    <div class="toast-body">${message}</div>
                    <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
                </div>
            </div>
        `;
        
        // Find or create toast container
        let toastContainer = document.getElementById('toast-container');
        if (!toastContainer) {
            toastContainer = document.createElement('div');
            toastContainer.id = 'toast-container';
            toastContainer.className = 'toast-container position-fixed top-0 end-0 p-3';
            toastContainer.style.zIndex = '1055';
            document.body.appendChild(toastContainer);
        }
        
        toastContainer.insertAdjacentHTML('beforeend', toastHtml);
        
        // Show toast
        const toastElement = document.getElementById(toastId);
        const toast = new bootstrap.Toast(toastElement, {
            autohide: true,
            delay: 3000
        });
        toast.show();
        
        // Remove from DOM after hiding
        toastElement.addEventListener('hidden.bs.toast', () => {
            toastElement.remove();
        });
    }
    
    showNotificationDetail(notification) {
        // Show detailed notification modal or navigate to relevant page
        console.log('Show detail for notification:', notification);
        
        // You can implement a modal or redirect to the calendar page
        window.location.href = 'follow_up_calendar.php';
    }
    
    destroy() {
        if (this.intervalId) {
            clearInterval(this.intervalId);
        }
    }
}

// Initialize notifications when DOM is ready
let followupNotifications;

document.addEventListener('DOMContentLoaded', function() {
    // Only initialize on team leader pages
    if (document.body.classList.contains('team-leader-page') || 
        window.location.pathname.includes('team_leader') ||
        document.querySelector('[data-role="team-leader"]')) {
        
        followupNotifications = new FollowupNotifications();
        
        // Make it globally accessible for onclick handlers
        window.followupNotifications = followupNotifications;
    }
});

// Clean up on page unload
window.addEventListener('beforeunload', function() {
    if (followupNotifications) {
        followupNotifications.destroy();
    }
});