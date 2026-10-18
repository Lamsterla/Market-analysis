    </main>

    <!-- Monitor Instructions Modal -->
    <div id="monitorModal" class="modal-backdrop" style="display: none;">
        <div class="modal-dialog">
            <div class="modal-header">
                <h3>Automatic Stop-Loss Monitor Status</h3>
                <button type="button" class="btn-close" onclick="toggleMonitorModal()">&times;</button>
            </div>
            <div class="modal-body">
                <div class="status-box <?= $monitorStatus['is_active'] ? 'box-active' : 'box-inactive' ?>">
                    <h4 id="modalStatusTitle"><?= $monitorStatus['is_active'] ? '🟢 Background Monitor is ACTIVE' : '🔴 Background Monitor is INACTIVE' ?></h4>
                    <p id="modalStatusDesc"><?= htmlspecialchars($monitorStatus['message']) ?></p>
                    <p class="text-muted"><small>Last Heartbeat: <?= htmlspecialchars($monitorStatus['last_heartbeat'] ?? 'Never') ?></small></p>
                </div>

                <div class="instruction-box">
                    <h5>How to Start Stop-Loss Monitoring on Windows:</h5>
                    <ol>
                        <li>Open a terminal or Command Prompt in Visual Studio Code.</li>
                        <li>Run the monitoring script:
                            <pre><code>python python/monitor_stop_loss.py</code></pre>
                        </li>
                        <li>To run in mock/test mode:
                            <pre><code>python python/monitor_stop_loss.py --mock</code></pre>
                        </li>
                        <li>To stop monitoring at any time: Press <strong>Ctrl + C</strong> in that terminal.</li>
                    </ol>
                    <div class="notice-note">
                        <strong>Important:</strong> Stop-loss execution is strictly simulated and requires the monitor script to be running. If the monitor is stopped, stop-losses will not automatically trigger until restarted or manually evaluated.
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="toggleMonitorModal()">Close</button>
                <button type="button" class="btn btn-primary" onclick="runManualStopLossCheck()">Check Stop-Loss Now</button>
            </div>
        </div>
    </div>

    <!-- Main Footer -->
    <footer class="main-footer">
        <div class="footer-container">
            <div class="footer-left">
                <p><strong>Stock Market Automated System</strong> &bull; Paper-Trading Simulator</p>
                <small class="text-muted">Market data provided by Yahoo Finance through Python <code>yfinance</code>. Strictly for educational &amp; simulation purposes.</small>
            </div>
            <div class="footer-right">
                <span class="system-time">System Time: <?= date('Y-m-d H:i:s T') ?></span>
            </div>
        </div>
    </footer>

    <!-- Vanilla JavaScript -->
    <script src="assets/js/app.js"></script>
</body>
</html>
