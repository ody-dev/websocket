/*
 *  This file is part of ODY framework.
 *
 *  @link     https://ody.dev
 *  @document https://ody.dev/docs
 *  @license  https://github.com/ody-dev/ody-foundation/blob/master/LICENSE
 */

/**
 * Ody WebSocket Client
 * A simple client for the Ody WebSocket server with channel support
 */
class OdyWebSocketClient {
    /**
     * Constructor
     *
     * @param {string} url WebSocket server URL (ws:// or wss://)
     * @param {string} apiKey API key for authentication
     * @param {Object} options Configuration options
     */
    constructor(url, apiKey, options = {}) {
        this.url = url;
        this.apiKey = apiKey;
        this.options = Object.assign({
            reconnectAttempts: 10,
            reconnectDelay: 3000,
            debug: false
        }, options);

        this.socket = null;
        this.socketId = null;
        this.connected = false;
        this.reconnectAttempts = 0;
        this.reconnecting = false;
        this.channels = {};
        this.eventCallbacks = {};

        // Bind methods
        this.connect = this.connect.bind(this);
        this.disconnect = this.disconnect.bind(this);
        this.subscribe = this.subscribe.bind(this);
        this.unsubscribe = this.unsubscribe.bind(this);
        this.publish = this.publish.bind(this);
        this.on = this.on.bind(this);
        this.off = this.off.bind(this);

        // Connect immediately if auto-connect is true
        if (this.options.autoConnect !== false) {
            this.connect();
        }
    }

    /**
     * Connect to the WebSocket server
     *
     * @return {Promise} Resolves when connected
     */
    connect() {
        return new Promise((resolve, reject) => {
            if (this.socket && this.connected) {
                resolve(this);
                return;
            }

            try {
                this.log('Connecting to WebSocket server...');

                // Create WebSocket connection
                this.socket = new WebSocket(this.url, this.apiKey);

                // Set up event handlers
                this.socket.onopen = () => {
                    this.log('WebSocket connection opened');
                    this.connected = true;
                    this.reconnectAttempts = 0;
                    this.reconnecting = false;

                    // Reset subscriptions on reconnect
                    Object.keys(this.channels).forEach(channel => {
                        if (this.channels[channel].subscribed) {
                            this.subscribe(channel, this.channels[channel].data);
                        }
                    });

                    resolve(this);
                    this.trigger('connected');
                };

                this.socket.onclose = (event) => {
                    this.log(`WebSocket connection closed: ${event.code} - ${event.reason}`);
                    this.connected = false;
                    this.socketId = null;

                    // Mark all channels as unsubscribed
                    Object.keys(this.channels).forEach(channel => {
                        this.channels[channel].subscribed = false;
                    });

                    this.trigger('disconnected', {code: event.code, reason: event.reason});

                    // Attempt to reconnect
                    if (!this.reconnecting && this.options.reconnectAttempts > 0) {
                        this.attemptReconnect();
                    }
                };

                this.socket.onerror = (error) => {
                    this.log('WebSocket error:', error);
                    this.trigger('error', error);

                    if (!this.connected) {
                        reject(error);
                    }
                };

                this.socket.onmessage = (event) => {
                    this.handleMessage(event.data);
                };
            } catch (error) {
                this.log('Connection error:', error);
                reject(error);

                // Attempt to reconnect on connection error
                if (!this.reconnecting && this.options.reconnectAttempts > 0) {
                    this.attemptReconnect();
                }
            }
        });
    }

    /**
     * Attempt to reconnect to the server
     *
     * @private
     */
    attemptReconnect() {
        if (this.reconnectAttempts >= this.options.reconnectAttempts) {
            this.log('Maximum reconnection attempts reached');
            this.trigger('reconnect_failed');
            return;
        }

        this.reconnecting = true;
        this.reconnectAttempts++;

        this.log(`Attempting to reconnect (${this.reconnectAttempts}/${this.options.reconnectAttempts})...`);
        this.trigger('reconnecting', {attempt: this.reconnectAttempts});

        setTimeout(() => {
            this.connect()
                .then(() => {
                    this.log('Reconnected successfully');
                    this.trigger('reconnected');
                })
                .catch(() => {
                    this.log('Reconnection failed');
                });
        }, this.options.reconnectDelay);
    }

    /**
     * Disconnect from the WebSocket server
     */
    disconnect() {
        if (this.socket) {
            this.log('Disconnecting from WebSocket server...');
            this.reconnectAttempts = this.options.reconnectAttempts; // Prevent auto-reconnect
            this.socket.close();
            this.socket = null;
            this.connected = false;
            this.socketId = null;
        }
    }

    /**
     * Subscribe to a channel
     *
     * @param {string} channelName Channel name
     * @param {Object} data Additional subscription data
     * @return {Channel} Channel object
     */
    subscribe(channelName, data = {}) {
        if (!this.connected) {
            throw new Error('Cannot subscribe to channel: not connected');
        }

        this.log(`Subscribing to channel: ${channelName}`);

        // Create channel if it doesn't exist
        if (!this.channels[channelName]) {
            this.channels[channelName] = {
                name: channelName,
                subscribed: false,
                data: data,
                eventCallbacks: {}
            };
        }

        // Send subscription request
        this.socket.send(JSON.stringify({
            event: 'subscribe',
            channel: channelName,
            data: data
        }));

        return {
            name: channelName,
            client: this,

            /**
             * Bind an event handler for this channel
             *
             * @param {string} event Event name
             * @param {Function} callback Event handler
             * @return {Channel} This channel
             */
            on: (event, callback) => {
                const eventKey = `${channelName}:${event}`;

                if (!this.eventCallbacks[eventKey]) {
                    this.eventCallbacks[eventKey] = [];
                }

                this.eventCallbacks[eventKey].push(callback);
                return this;
            },

            /**
             * Unbind an event handler for this channel
             *
             * @param {string} event Event name
             * @param {Function} callback Event handler (optional)
             * @return {Channel} This channel
             */
            off: (event, callback) => {
                const eventKey = `${channelName}:${event}`;

                if (this.eventCallbacks[eventKey]) {
                    if (callback) {
                        this.eventCallbacks[eventKey] = this.eventCallbacks[eventKey].filter(cb => cb !== callback);
                    } else {
                        delete this.eventCallbacks[eventKey];
                    }
                }

                return this;
            },

            /**
             * Publish an event to this channel
             *
             * @param {string} event Event name
             * @param {Object} data Event data
             * @return {Channel} This channel
             */
            publish: (event, data = {}) => {
                this.publish(channelName, event, data);
                return this;
            },

            /**
             * Unsubscribe from this channel
             *
             * @return {void}
             */
            unsubscribe: () => {
                this.unsubscribe(channelName);
            }
        };
    }

    /**
     * Unsubscribe from a channel
     *
     * @param {string} channelName Channel name
     */
    unsubscribe(channelName) {
        if (!this.connected) {
            return;
        }

        this.log(`Unsubscribing from channel: ${channelName}`);

        // Send unsubscription request
        this.socket.send(JSON.stringify({
            event: 'unsubscribe',
            channel: channelName
        }));

        // Mark channel as unsubscribed
        if (this.channels[channelName]) {
            this.channels[channelName].subscribed = false;
        }
    }

    /**
     * Publish an event to a channel
     *
     * @param {string} channelName Channel name
     * @param {string} event Event name
     * @param {Object} data Event data
     */
    publish(channelName, event, data = {}) {
        if (!this.connected) {
            throw new Error('Cannot publish to channel: not connected');
        }

        // Check if subscribed to the channel
        if (!this.channels[channelName] || !this.channels[channelName].subscribed) {
            throw new Error(`Cannot publish to channel ${channelName}: not subscribed`);
        }

        this.log(`Publishing event ${event} to channel ${channelName}`);

        // Send publish request
        this.socket.send(JSON.stringify({
            event: 'message',
            channel: channelName,
            name: event,
            data: data
        }));
    }

    /**
     * Register a global event handler
     *
     * @param {string} event Event name
     * @param {Function} callback Event handler
     * @return {OdyWebSocketClient} This client
     */
    on(event, callback) {
        if (!this.eventCallbacks[event]) {
            this.eventCallbacks[event] = [];
        }

        this.eventCallbacks[event].push(callback);
        return this;
    }

    /**
     * Unregister a global event handler
     *
     * @param {string} event Event name
     * @param {Function} callback Event handler (optional)
     * @return {OdyWebSocketClient} This client
     */
    off(event, callback) {
        if (this.eventCallbacks[event]) {
            if (callback) {
                this.eventCallbacks[event] = this.eventCallbacks[event].filter(cb => cb !== callback);
            } else {
                delete this.eventCallbacks[event];
            }
        }

        return this;
    }

    /**
     * Handle an incoming message
     *
     * @private
     * @param {string} data Message data
     */
    handleMessage(data) {
        try {
            const message = JSON.parse(data);

            this.log('Received message:', message);

            // Handle connection established event
            if (message.event === 'connection_established') {
                this.socketId = message.data.socket_id;
                this.trigger('socket_id', this.socketId);
                return;
            }

            // Handle subscription succeeded event
            if (message.event === 'subscription_succeeded') {
                const channelName = message.data.channel;

                if (this.channels[channelName]) {
                    this.channels[channelName].subscribed = true;
                    this.trigger('subscription_succeeded', {channel: channelName});
                    this.trigger(`${channelName}:subscription_succeeded`, {channel: channelName});
                }

                return;
            }

            // Handle subscription error event
            if (message.event === 'subscription_error') {
                const channelName = message.data.channel;

                this.trigger('subscription_error', {
                    channel: channelName,
                    message: message.data.message
                });

                this.trigger(`${channelName}:subscription_error`, {
                    channel: channelName,
                    message: message.data.message
                });

                return;
            }

            // Handle unsubscribed event
            if (message.event === 'unsubscribed') {
                const channelName = message.data.channel;

                if (this.channels[channelName]) {
                    this.channels[channelName].subscribed = false;
                    this.trigger('unsubscribed', {channel: channelName});
                    this.trigger(`${channelName}:unsubscribed`, {channel: channelName});
                }

                return;
            }

            // Handle error event
            if (message.event === 'error') {
                this.trigger('error', message.data);
                return;
            }

            // Handle channel events
            if (message.channel) {
                const channelName = message.channel;
                const event = message.event;
                const eventData = message.data;

                // Trigger channel event
                this.trigger(`${channelName}:${event}`, eventData);
            }
        } catch (error) {
            this.log('Error handling message:', error);
            this.log('Message data:', data);
        }
    }

    /**
     * Trigger an event
     *
     * @private
     * @param {string} event Event name
     * @param {Object} data Event data
     */
    trigger(event, data = {}) {
        if (this.eventCallbacks[event]) {
            this.eventCallbacks[event].forEach(callback => {
                try {
                    callback(data);
                } catch (error) {
                    this.log(`Error in event handler for ${event}:`, error);
                }
            });
        }
    }

    /**
     * Log a message if debug is enabled
     *
     * @private
     * @param {...any} args Arguments to log
     */
    log(...args) {
        if (this.options.debug) {
            console.log('[OdyWebSocketClient]', ...args);
        }
    }

    /**
     * Get the socket ID
     *
     * @return {string|null} Socket ID
     */
    getSocketId() {
        return this.socketId;
    }

    /**
     * Check if connected to the server
     *
     * @return {boolean} True if connected
     */
    isConnected() {
        return this.connected;
    }
}

// Export for CommonJS or browser
if (typeof module !== 'undefined' && module.exports) {
    module.exports = OdyWebSocketClient;
} else if (typeof window !== 'undefined') {
    window.OdyWebSocketClient = OdyWebSocketClient;
}