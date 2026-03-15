import { Head } from '@inertiajs/react'
import { AlertCircle, Bot, Send, User } from 'lucide-react'
import { useEffect, useRef, useState } from 'react'
import type { FormEvent } from 'react'

import { store } from '@/actions/App/Http/Controllers/ChatController'
import { Button } from '@/components/ui/button'
import AppLayout from '@/layouts/app-layout'
import type { BreadcrumbItem } from '@/types'

type Message = {
    role: 'user' | 'assistant'
    content: string
}

type PageProps = {
    hasDocuments: boolean
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: '/dashboard' },
    { title: 'Chat', href: '/chat' },
]

export default function ChatIndex({ hasDocuments }: PageProps) {
    const [messages, setMessages] = useState<Message[]>([])
    const [input, setInput] = useState('')
    const [loading, setLoading] = useState(false)
    const [conversationId, setConversationId] = useState<string | null>(null)
    const messagesEndRef = useRef<HTMLDivElement>(null)
    const inputRef = useRef<HTMLTextAreaElement>(null)

    useEffect(() => {
        messagesEndRef.current?.scrollIntoView({ behavior: 'smooth' })
    }, [messages])

    async function handleSubmit(e: FormEvent) {
        e.preventDefault()
        const trimmed = input.trim()

        if (!trimmed || loading) return

        const userMessage: Message = { role: 'user', content: trimmed }
        setMessages((prev) => [...prev, userMessage])
        setInput('')
        setLoading(true)

        try {
            const { url, method } = store()

            const response = await fetch(url, {
                method,
                headers: {
                    'Content-Type': 'application/json',
                    'X-XSRF-TOKEN': getCsrfToken(),
                    Accept: 'application/json',
                },
                body: JSON.stringify({
                    message: trimmed,
                    conversation_id: conversationId,
                }),
                credentials: 'same-origin',
            })

            if (!response.ok) {
                const errorBody = await response.text()
                console.error('Chat error:', response.status, errorBody)
                setMessages((prev) => [
                    ...prev,
                    {
                        role: 'assistant',
                        content:
                            'Sorry, something went wrong. Please try again.',
                    },
                ])
            } else {
                const data = await response.json()

                setMessages((prev) => [
                    ...prev,
                    {
                        role: 'assistant',
                        content: data.text || 'No response received.',
                    },
                ])

                if (data.conversationId) {
                    setConversationId(data.conversationId)
                }
            }
        } catch (error) {
            console.error('Chat error:', error)
            setMessages((prev) => [
                ...prev,
                {
                    role: 'assistant',
                    content: 'Sorry, something went wrong. Please try again.',
                },
            ])
        }

        setLoading(false)
        inputRef.current?.focus()
    }

    function getCsrfToken(): string {
        const match = document.cookie.match(/XSRF-TOKEN=([^;]+)/)

        return match ? decodeURIComponent(match[1]) : ''
    }

    function handleKeyDown(e: React.KeyboardEvent) {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault()
            handleSubmit(e as unknown as FormEvent)
        }
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Chat" />
            <div className="flex h-full flex-1 flex-col overflow-hidden">
                {!hasDocuments && (
                    <div className="mx-4 mt-4 flex items-center gap-2 rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-800 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-200">
                        <AlertCircle className="size-4 shrink-0" />
                        No documents uploaded yet. Upload documents first to get
                        contextual answers.
                    </div>
                )}

                <div className="flex-1 overflow-y-auto p-4">
                    {messages.length === 0 && (
                        <div className="flex h-full flex-col items-center justify-center gap-4">
                            <Bot className="size-12 text-muted-foreground" />
                            <div className="text-center">
                                <h2 className="text-lg font-semibold">
                                    RAG Assistant
                                </h2>
                                <p className="text-sm text-muted-foreground">
                                    Ask questions about your uploaded documents.
                                </p>
                            </div>
                        </div>
                    )}

                    <div className="mx-auto flex max-w-3xl flex-col gap-4">
                        {messages.map((msg, i) => (
                            <div
                                key={i}
                                className={`flex gap-3 ${msg.role === 'user' ? 'justify-end' : 'justify-start'}`}
                            >
                                {msg.role === 'assistant' && (
                                    <div className="flex size-8 shrink-0 items-center justify-center rounded-full bg-primary/10">
                                        <Bot className="size-4" />
                                    </div>
                                )}
                                <div
                                    className={`max-w-[80%] rounded-2xl px-4 py-2.5 text-sm whitespace-pre-wrap ${
                                        msg.role === 'user'
                                            ? 'bg-primary text-primary-foreground'
                                            : 'bg-muted'
                                    }`}
                                >
                                    {msg.content}
                                </div>
                                {msg.role === 'user' && (
                                    <div className="flex size-8 shrink-0 items-center justify-center rounded-full bg-secondary">
                                        <User className="size-4" />
                                    </div>
                                )}
                            </div>
                        ))}
                        {loading && (
                            <div className="flex justify-start gap-3">
                                <div className="flex size-8 shrink-0 items-center justify-center rounded-full bg-primary/10">
                                    <Bot className="size-4" />
                                </div>
                                <div className="rounded-2xl bg-muted px-4 py-2.5">
                                    <span className="inline-flex gap-1">
                                        <span className="size-1.5 animate-bounce rounded-full bg-foreground/40 [animation-delay:0ms]" />
                                        <span className="size-1.5 animate-bounce rounded-full bg-foreground/40 [animation-delay:150ms]" />
                                        <span className="size-1.5 animate-bounce rounded-full bg-foreground/40 [animation-delay:300ms]" />
                                    </span>
                                </div>
                            </div>
                        )}
                        <div ref={messagesEndRef} />
                    </div>
                </div>

                <div className="border-t p-4">
                    <form
                        onSubmit={handleSubmit}
                        className="mx-auto flex max-w-3xl gap-2"
                    >
                        <textarea
                            ref={inputRef}
                            value={input}
                            onChange={(e) => setInput(e.target.value)}
                            onKeyDown={handleKeyDown}
                            placeholder="Ask about your documents..."
                            rows={1}
                            className="flex-1 resize-none rounded-lg border border-input bg-background px-4 py-2.5 text-sm ring-offset-background placeholder:text-muted-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-50"
                            disabled={loading}
                        />
                        <Button
                            type="submit"
                            size="icon"
                            disabled={loading || !input.trim()}
                        >
                            <Send className="size-4" />
                        </Button>
                    </form>
                </div>
            </div>
        </AppLayout>
    )
}
