/**
 * Advanced Screenshot and Recording Prevention System
 * Implements multiple layers of protection for sensitive team leader pages
 */

class SecurityProtection {
    constructor(options = {}) {
        this.options = {
            enableWatermark: true,
            enableBlurOnFocus: true,
            enableKeybindBlocking: true,
            enableContextMenuBlocking: true,
            enableDevToolsBlocking: true,
            enableScreenRecordingDetection: true,
            enableTabSwitchDetection: true,
            watermarkText: options.watermarkText || 'CONFIDENTIAL',
            userId: options.userId || 'USER',
            sessionId: options.sessionId || 'SESSION',
            logEndpoint: options.logEndpoint || null,
            violationCallback: options.violationCallback || null,
            maxViolations: options.maxViolations || 10,
            ...options
        };
        
        this.violationCount = 0;
        this.isPageVisible = true;
        this.lastFrameTime = performance.now();
        this.suspiciousActivityDetected = false;
        this.protectionActive = true;
        
        this.init();
    }

    init() {
        if (!this.protectionActive) return;
        
        this.setupWatermark();
        this.blockKeyboardShortcuts();
        this.blockContextMenu();
        
        // Only enable these features if explicitly requested
        if (this.options.enableDevToolsBlocking) {
            this.blockDevTools();
        }
        if (this.options.enableBlurOnFocus) {
            this.setupBlurOnFocusLoss();
        }
        if (this.options.enableTabSwitchDetection) {
            this.detectTabSwitching();
        }
        if (this.options.enableScreenRecordingDetection) {
            this.detectScreenRecording();
        }
        
        this.setupPeriodicChecks();
        this.preventTextSelection();
        this.blockPrintScreen();
        
        // Security Protection Active - logging disabled to prevent console access detection
    }

    setupWatermark() {
        if (!this.options.enableWatermark) return;
        
        const watermark = document.createElement('div');
        watermark.id = 'security-watermark';
        watermark.innerHTML = `
            <div class="watermark-content">
                ${this.options.watermarkText}<br>
                ${this.options.userId}<br>
                ${new Date().toLocaleString()}
            </div>
        `;
        
        const style = document.createElement('style');
        style.textContent = `
            #security-watermark {
                position: fixed;
                top: 0;
                left: 0;
                width: 100vw;
                height: 100vh;
                pointer-events: none;
                z-index: 9999;
                opacity: 0.12;
                user-select: none;
                background-image: repeating-linear-gradient(
                    45deg,
                    transparent,
                    transparent 150px,
                    rgba(0, 0, 0, 0.03) 150px,
                    rgba(0, 0, 0, 0.03) 300px
                );
            }
            
            .watermark-content {
                position: absolute;
                top: 50%;
                left: 50%;
                transform: translate(-50%, -50%) rotate(-35deg);
                font-size: 28px;
                font-weight: 600;
                color: #000;
                text-align: center;
                white-space: nowrap;
                font-family: 'Arial', sans-serif;
            }
            
            body.page-blurred #security-watermark {
                opacity: 0.8;
                background-color: rgba(0, 0, 0, 0.7);
            }
            
            body.page-blurred .watermark-content {
                color: #ff4444;
                font-size: 36px;
                animation: pulse 2s infinite;
            }
            
            @keyframes pulse {
                0%, 100% { opacity: 0.8; }
                50% { opacity: 1; }
            }
            
            .security-warning {
                position: fixed;
                top: 20px;
                right: 20px;
                background: #ff4444;
                color: white;
                padding: 10px 15px;
                border-radius: 5px;
                font-weight: bold;
                z-index: 10000;
                box-shadow: 0 4px 8px rgba(0,0,0,0.3);
            }
        `;
        
        document.head.appendChild(style);
        document.body.appendChild(watermark);
    }

    blockKeyboardShortcuts() {
        if (!this.options.enableKeybindBlocking) return;
        
        const blockedKeys = [
            // Only block critical screenshot shortcuts
            { key: 'PrintScreen' },
            { key: 's', ctrl: true, shift: true }, // Chrome screenshot
            { key: 's', meta: true, shift: true }, // Mac screenshot
            
            // Only block F12 developer tools
            { key: 'F12' },
            { key: 'I', ctrl: true, shift: true },
            
            // Allow other shortcuts but log them
        ];
        
        document.addEventListener('keydown', (e) => {
            const blocked = blockedKeys.some(blocked => {
                const keyMatch = blocked.key.toLowerCase() === e.key.toLowerCase();
                const ctrlMatch = blocked.ctrl ? e.ctrlKey : !e.ctrlKey;
                const metaMatch = blocked.meta ? e.metaKey : !e.metaKey;
                const shiftMatch = blocked.shift ? e.shiftKey : !e.shiftKey;
                
                return keyMatch && ctrlMatch && metaMatch && shiftMatch;
            });
            
            if (blocked) {
                e.preventDefault();
                e.stopPropagation();
                e.stopImmediatePropagation();
                this.logViolation('blocked_keyboard_shortcut', {
                    key: e.key,
                    ctrl: e.ctrlKey,
                    meta: e.metaKey,
                    shift: e.shiftKey
                });
                return false;
            }
        }, true);
        
        // Block common function keys
        document.addEventListener('keyup', (e) => {
            if (['F12', 'PrintScreen'].includes(e.key)) {
                this.logViolation('function_key_pressed', { key: e.key });
            }
        });
    }

    blockContextMenu() {
        if (!this.options.enableContextMenuBlocking) return;
        
        document.addEventListener('contextmenu', (e) => {
            // Only block right-click on sensitive content, allow on form inputs
            if (['INPUT', 'TEXTAREA', 'SELECT'].includes(e.target.tagName)) {
                return true; // Allow right-click on form elements
            }
            
            e.preventDefault();
            // Don't log as violation for less sensitivity
            // this.logViolation('right_click_attempt');
            return false;
        });
    }

    blockDevTools() {
        if (!this.options.enableDevToolsBlocking) return;
        
        // Detect dev tools by checking window size changes
        let devtools = { open: false, orientation: null };
        
        setInterval(() => {
            if (window.outerHeight - window.innerHeight > 160 || 
                window.outerWidth - window.innerWidth > 160) {
                if (!devtools.open) {
                    devtools.open = true;
                    this.logViolation('devtools_opened');
                    this.handleSuspiciousActivity('Developer tools detected');
                }
            } else {
                devtools.open = false;
            }
        }, 500);
        
        // Detect console usage
        let devtools_console = {
            open: false,
            element: console.log
        };
        
        Object.defineProperty(console, 'log', {
            get: function() {
                devtools_console.open = true;
                return devtools_console.element;
            }
        });
        
        setInterval(() => {
            if (devtools_console.open) {
                this.logViolation('console_accessed');
                this.handleSuspiciousActivity('Console access detected');
                devtools_console.open = false;
            }
        }, 1000);
    }

    setupBlurOnFocusLoss() {
        if (!this.options.enableBlurOnFocus) return;
        
        const blurContent = () => {
            document.body.classList.add('page-blurred');
            this.isPageVisible = false;
        };
        
        const unblurContent = () => {
            document.body.classList.remove('page-blurred');
            this.isPageVisible = true;
        };
        
        document.addEventListener('visibilitychange', () => {
            if (document.hidden) {
                blurContent();
                this.logViolation('page_hidden');
            } else {
                unblurContent();
            }
        });
        
        window.addEventListener('blur', () => {
            blurContent();
            this.logViolation('window_blur');
        });
        
        window.addEventListener('focus', unblurContent);
        
        // CSS for blur effect
        const style = document.createElement('style');
        style.textContent = `
            body.page-blurred .main-content,
            body.page-blurred main,
            body.page-blurred .card-body,
            body.page-blurred .table {
                filter: blur(10px);
                transition: filter 0.3s ease;
            }
        `;
        document.head.appendChild(style);
    }

    detectTabSwitching() {
        if (!this.options.enableTabSwitchDetection) return;
        
        document.addEventListener('visibilitychange', () => {
            if (document.hidden) {
                this.logViolation('tab_switch_away');
                setTimeout(() => {
                    if (document.hidden) {
                        this.logViolation('extended_tab_switch', { duration: '5s' });
                    }
                }, 5000);
            }
        });
    }

    detectScreenRecording() {
        if (!this.options.enableScreenRecordingDetection) return;
        
        // Monitor frame rate drops which may indicate screen recording
        const monitorFrameRate = () => {
            const now = performance.now();
            const frameDuration = now - this.lastFrameTime;
            this.lastFrameTime = now;
            
            // Detect unusual frame patterns
            if (frameDuration > 100) { // Significant frame drop
                this.logViolation('frame_rate_anomaly', { duration: frameDuration });
            }
            
            requestAnimationFrame(monitorFrameRate);
        };
        
        requestAnimationFrame(monitorFrameRate);
        
        // Check for screen capture APIs
        if ('getDisplayMedia' in navigator.mediaDevices) {
            const originalGetDisplayMedia = navigator.mediaDevices.getDisplayMedia;
            navigator.mediaDevices.getDisplayMedia = function(...args) {
                this.logViolation('screen_capture_api_called');
                this.handleSuspiciousActivity('Screen capture API accessed');
                return originalGetDisplayMedia.apply(this, args);
            }.bind(this);
        }
        
        // Monitor for unusual CPU/memory usage patterns
        setInterval(() => {
            if (performance.memory) {
                const memUsage = performance.memory.usedJSHeapSize / 1024 / 1024;
                if (memUsage > 100) { // High memory usage might indicate recording
                    this.logViolation('high_memory_usage', { usage: memUsage });
                }
            }
        }, 5000);
    }

    preventTextSelection() {
        const style = document.createElement('style');
        style.textContent = `
            body {
                -webkit-user-select: none;
                -moz-user-select: none;
                -ms-user-select: none;
                user-select: none;
                -webkit-touch-callout: none;
                -webkit-tap-highlight-color: transparent;
            }
            
            input, textarea, [contenteditable] {
                -webkit-user-select: text;
                -moz-user-select: text;
                -ms-user-select: text;
                user-select: text;
            }
        `;
        document.head.appendChild(style);
        
        document.addEventListener('selectstart', (e) => {
            if (!['INPUT', 'TEXTAREA'].includes(e.target.tagName)) {
                e.preventDefault();
            }
        });
        
        document.addEventListener('dragstart', (e) => {
            e.preventDefault();
        });
    }

    blockPrintScreen() {
        // Override clipboard access
        document.addEventListener('copy', (e) => {
            if (!['INPUT', 'TEXTAREA'].includes(e.target.tagName)) {
                e.clipboardData.setData('text/plain', 'COPYING DISABLED FOR SECURITY');
                e.preventDefault();
                this.logViolation('copy_attempt');
            }
        });
        
        // Monitor for clipboard changes
        if (navigator.clipboard) {
            const originalWriteText = navigator.clipboard.writeText;
            navigator.clipboard.writeText = function(...args) {
                this.logViolation('clipboard_write_attempt');
                return Promise.reject('Clipboard access blocked');
            }.bind(this);
        }
    }

    detectInspectorOpening() {
        // Monitor for element inspection
        document.addEventListener('keydown', (e) => {
            if ((e.ctrlKey || e.metaKey) && e.shiftKey && e.key === 'C') {
                this.logViolation('inspector_shortcut_attempt');
            }
        });
        
        // Detect right-click on elements (common for inspect element)
        document.addEventListener('mousedown', (e) => {
            if (e.button === 2) { // Right click
                this.logViolation('right_click_on_element', {
                    element: e.target.tagName,
                    id: e.target.id,
                    className: e.target.className
                });
            }
        });
    }

    setupPeriodicChecks() {
        // Periodic security status check
        setInterval(() => {
            this.performSecurityCheck();
        }, 10000); // Every 10 seconds
        
        // Heartbeat to server
        if (this.options.logEndpoint) {
            setInterval(() => {
                this.sendHeartbeat();
            }, 30000); // Every 30 seconds
        }
    }

    performSecurityCheck() {
        const checks = [
            { name: 'watermark', condition: document.getElementById('security-watermark') },
            { name: 'page_visible', condition: this.isPageVisible },
            { name: 'protection_active', condition: this.protectionActive }
        ];
        
        checks.forEach(check => {
            if (!check.condition) {
                this.logViolation('security_check_failed', { check: check.name });
            }
        });
    }

    logViolation(type, details = {}) {
        this.violationCount++;
        
        const violation = {
            type,
            details,
            timestamp: new Date().toISOString(),
            userAgent: navigator.userAgent,
            url: window.location.href,
            userId: this.options.userId,
            sessionId: this.options.sessionId,
            violationCount: this.violationCount
        };
        
        // Security violation logged - console output disabled to prevent detection loops
        
        // Send to server if endpoint provided
        if (this.options.logEndpoint) {
            fetch(this.options.logEndpoint, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify(violation)
            }).catch(err => {/* Failed to log violation - silent to prevent console access */});
        }
        
        // Call violation callback
        if (this.options.violationCallback) {
            this.options.violationCallback(violation);
        }
        
        // Handle multiple violations
        if (this.violationCount >= this.options.maxViolations) {
            this.handleSuspiciousActivity('Too many security violations');
        }
    }

    handleSuspiciousActivity(reason) {
        this.suspiciousActivityDetected = true;
        
        this.showWarning(`SECURITY ALERT: ${reason}`);
        
        // Optionally redirect or lock the session
        if (this.options.violationCallback) {
            this.options.violationCallback({
                type: 'suspicious_activity',
                reason,
                timestamp: new Date().toISOString(),
                action: 'session_terminated'
            });
        }
        
        // Log critical violation
        this.logViolation('suspicious_activity_detected', { reason });
    }

    showWarning(message) {
        const warning = document.createElement('div');
        warning.className = 'security-warning';
        warning.textContent = message;
        warning.innerHTML += ' <button onclick="this.parentNode.remove()">×</button>';
        
        document.body.appendChild(warning);
        
        setTimeout(() => {
            if (warning.parentNode) {
                warning.parentNode.removeChild(warning);
            }
        }, 5000);
    }

    sendHeartbeat() {
        if (!this.options.logEndpoint) return;
        
        const heartbeat = {
            type: 'heartbeat',
            timestamp: new Date().toISOString(),
            userId: this.options.userId,
            sessionId: this.options.sessionId,
            pageVisible: this.isPageVisible,
            violationCount: this.violationCount,
            protectionActive: this.protectionActive
        };
        
        fetch(this.options.logEndpoint, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify(heartbeat)
        }).catch(err => {/* Heartbeat failed - silent to prevent console access */});
    }

    disable() {
        this.protectionActive = false;
        
        // Remove watermark
        const watermark = document.getElementById('security-watermark');
        if (watermark) {
            watermark.remove();
        }
        
        // Remove blur class
        document.body.classList.remove('page-blurred');
        
        // Security protection disabled - logging removed to prevent console access detection
    }
}

// Global security protection instance
window.SecurityProtection = SecurityProtection;