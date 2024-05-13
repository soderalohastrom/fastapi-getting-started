from fastapi import FastAPI
from pydantic import BaseModel, Field
from rerankers import Reranker
from typing import List

app = FastAPI()
# ranker = Reranker("cohere", lang='en', api_key='n1ytpDT5S9jVqY1abqvqoD6flMgo8M25UJce9fLy')
ranker = Reranker("colbert")

class Document(BaseModel):
    doc_id: int = Field(..., description="The unique ID of the document")
    text: str = Field(..., description="The text of the document")

class RerankRequest(BaseModel):
    query: str = Field(..., description="The query to rank the documents against")
    documents: List[Document] = Field(..., description="The documents to be reranked")

class RerankResponse(BaseModel):
    reranked_documents: List[Document] = Field(..., description="The reranked documents")

@app.post("/rerank", response_model=RerankResponse)
async def rerank_documents(rerank_request: RerankRequest):
    docs = [doc.text for doc in rerank_request.documents]
    doc_ids = [doc.doc_id for doc in rerank_request.documents]
    reranked_results = ranker.rank(
        query=rerank_request.query,
        docs=docs,
        doc_ids=doc_ids
    )
    reranked_documents = [
        Document(doc_id=result.doc_id, text=result.text)
        for result in reranked_results.results
    ]
    return RerankResponse(reranked_documents=reranked_documents)
